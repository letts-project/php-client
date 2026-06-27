<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

use Letts\Exceptions\NetworkException;
use Letts\Exceptions\WaitTimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * NDJSON streamer for /v1/missions/{id}/events. Iterates one event per line,
 * calls a callback per Event. Callback returns false to stop iteration
 * (e.g. on terminal `done` event). On EOF before a stop signal, reconnects
 * with `from=<lastSeq>` appended to resume from the last delivered seq.
 *
 * The response is consumed chunk-by-chunk via HttpClient::stream() rather
 * than through a blocking fread() wrapper. That matters for quiet streams:
 * while nothing is emitted, the poll yields timeout chunks that we skip
 * silently — a long silence is normal for the events endpoint, not an error
 * (the fread path would surface each idle period as a PHP warning). The poll
 * cadence also bounds how late a wait deadline can fire.
 *
 * The status is read off the first chunk, never via a blocking accessor before
 * the poll loop (that would stall on a quiet stream, whose 200 headers the
 * server defers until the first event): 4xx maps to the Letts exception
 * hierarchy (definitive); 5xx and an event-less drop consume the reconnect
 * budget (see follow()).
 */
final class EventStream
{
    private const POLL_SECONDS = 1.0;
    private const RECONNECT_BACKOFF_MS = [100, 500, 2000];
    // Absolute cap on the number of reconnects within one follow(): a backstop
    // against a server that delivers an event then drops in a loop (each
    // delivery resets the consecutive budget, so it alone would never fail).
    // It does NOT bound a single healthy connection that stays open and quiet —
    // that is the caller's deadline to enforce.
    private const MAX_TOTAL_RECONNECTS = 1000;

    public function __construct(private readonly HttpTransport $transport) {}

    /**
     * Follow with automatic reconnect from last_seq on EOF before terminal
     * event. Caller's callback returns false to stop (e.g. on `done`); if the
     * stream closes without a stop signal, we GET again with ?from=<lastSeq>
     * appended (existing query params preserved).
     *
     * Reconnect budget: a reconnect that delivered NO new events (5xx, connect
     * failure, or a drop before any further event) consumes maxReconnects; one
     * that delivered new events resets it, so a long stream over many genuine
     * reconnects never trips a small cap while a truly broken stream fails fast.
     * MAX_TOTAL_RECONNECTS is an absolute backstop on the reconnect count. On
     * exhaustion the exception lists the recent per-reconnect reasons (clean EOF
     * vs transport drop vs 5xx) so the underlying cause is visible.
     *
     * Note: a quiet-but-healthy connection is held open across idle polls by
     * streamOnce() (no reconnect at all), so follow() reconnects only on a
     * genuine end. A deadline-less follow() therefore waits as long as the
     * connection stays alive and silent — pass a deadline to bound that.
     *
     * @param \Closure(Event): bool $onEvent
     */
    public function follow(string $path, \Closure $onEvent, int $maxReconnects = 3, ?float $deadline = null): void
    {
        $lastSeq = 0;
        $lastResetSeq = 0;
        $reconnects = 0;        // consecutive reconnects that delivered no new events
        $totalReconnects = 0;   // absolute backstop
        $url = $path;
        $history = [];          // recent "<reason> (<seconds>s)" entries, for the exhaustion message
        while (true) {
            $startedAt = microtime(true);
            $r = $this->streamOnce($url, $onEvent, $lastSeq, $deadline);
            if ($r['stopped']) {
                return; // callback consumed its terminal event
            }
            // Disconnected (or clean EOF) before a stop signal.
            $this->assertDeadline($deadline);
            $history[] = sprintf('%s (%.1fs)', $r['reason'], microtime(true) - $startedAt);

            if (++$totalReconnects > self::MAX_TOTAL_RECONNECTS) {
                throw $this->reconnectExhausted($path, 'total', self::MAX_TOTAL_RECONNECTS, $history);
            }

            // New events since the last reset = real progress → forgive the
            // budget. A reconnect that delivered nothing accumulates it, so a
            // broken stream fails fast at the small cap.
            if ($lastSeq > $lastResetSeq) {
                $reconnects = 0;
                $lastResetSeq = $lastSeq;
            } elseif (++$reconnects > $maxReconnects) {
                throw $this->reconnectExhausted($path, 'consecutive', $maxReconnects, $history);
            }

            $this->backoff(max(1, $reconnects), $deadline);
            $url = $this->withFromSeq($path, $lastSeq);
        }
    }

    /** @param list<string> $history */
    private function reconnectExhausted(string $path, string $kind, int $limit, array $history): NetworkException
    {
        $recent = implode('; ', array_slice($history, -8));
        return new NetworkException(
            $this->transport->host(),
            sprintf(
                'event stream reconnect budget exhausted (%s limit %d) for %s — recent reconnects: [%s]',
                $kind, $limit, $path, $recent !== '' ? $recent : 'none',
            ),
        );
    }

    /**
     * Open ONE connection and pump events until it genuinely ends.
     *
     * @return array{stopped: bool, reason: string}
     *   stopped: the callback consumed the terminal event.
     *   reason:  why the connection ended ('done' | 'eof' | 'http_5xx' |
     *            'transport: <msg>') — surfaced in the reconnect-exhausted error.
     * A definitive 4xx and an elapsed deadline abort the whole follow by throwing.
     *
     * Symfony's stream($r, POLL) yields a SINGLE timeout chunk after POLL
     * seconds of upstream silence and then ENDS the generator — the timeout is
     * per stream() call, not a continuous poll. A lone foreach would therefore
     * return after the first idle second, and follow() would mistake that for a
     * disconnect and reconnect every ~1s — for a stream that runs quietly for a
     * while every such reconnect is event-less, so a small budget is exhausted
     * in seconds. So we RE-ENTER stream() on the same response after each idle
     * poll: one connection stays open across the whole quiet stretch (re-entry
     * also keeps resetting the request idle timeout), and we hand back to
     * follow() only on a genuine close (real EOF / transport drop / 5xx).
     */
    private function streamOnce(string $url, \Closure $onEvent, int &$lastSeq, ?float $deadline): array
    {
        $response = $this->transport->streamRequest('GET', $url);

        // Do NOT call $response->getStatusCode() before the loop: the server
        // defers the 200 header flush until the first event, so it would block
        // on a quiet stream. The status is read off the first chunk instead.
        $errStatus = null;
        $buf = '';
        try {
            while (true) {
                $idlePoll = false;
                foreach ($this->transport->streamChunks($response, self::POLL_SECONDS) as $chunk) {
                    if ($chunk->isTimeout()) {
                        // Idle poll wakeup — acknowledge it (so its ErrorChunk
                        // destructor won't re-throw) and check the deadline. If
                        // we are mid-4xx the body has stalled and is not a live
                        // stream, so stop and throw below; otherwise re-enter
                        // stream() to keep THIS connection alive.
                        $this->assertDeadline($deadline, $response);
                        if ($errStatus === null) {
                            $idlePoll = true;
                        }
                        break;
                    }
                    if ($chunk->isLast()) {
                        // Upstream closed the response — fall through to the
                        // errStatus check / clean-EOF return below.
                        break;
                    }
                    if ($chunk->isFirst()) {
                        // Headers are in now — getStatusCode() no longer blocks.
                        $status = $response->getStatusCode();
                        if ($status >= 500) {
                            $response->cancel();
                            return ['stopped' => false, 'reason' => "http_$status"];
                        }
                        if ($status >= 400) {
                            // Auth/gone/not-found: the body is an error document,
                            // not a stream. Collect it from the chunks below,
                            // then map+throw after the loop.
                            $errStatus = $status;
                        }
                        continue;
                    }
                    if ($errStatus !== null) {
                        $buf .= $chunk->getContent();
                        continue;
                    }
                    $buf .= $chunk->getContent();
                    while (($nl = strpos($buf, "\n")) !== false) {
                        $line = substr($buf, 0, $nl);
                        $buf = substr($buf, $nl + 1);
                        if ($line === '') {
                            continue;
                        }
                        $data = json_decode($line, true);
                        if (!is_array($data)) {
                            continue;
                        }
                        $ev = Event::fromJson($data);
                        if ($ev->seq > 0) {
                            $lastSeq = $ev->seq;
                        }
                        if ($onEvent($ev) === false) {
                            $response->cancel();
                            return ['stopped' => true, 'reason' => 'done'];
                        }
                    }
                    $this->assertDeadline($deadline, $response);
                }
                if ($idlePoll) {
                    continue; // same connection, next poll
                }
                break; // isLast, a stalled 4xx body, or a silent generator end
            }
            if ($errStatus !== null) {
                // Definitive HTTP refusal — abort the whole follow (not a
                // TransportException, so it escapes the catch below).
                $response->cancel();
                throw HttpTransport::mapError($errStatus, $buf);
            }
        } catch (TransportExceptionInterface $e) {
            $response->cancel();
            return ['stopped' => false, 'reason' => 'transport: '.$e->getMessage()];
        }
        return ['stopped' => false, 'reason' => 'eof'];
    }

    private function assertDeadline(?float $deadline, ?ResponseInterface $toCancel = null): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            $toCancel?->cancel();
            throw new WaitTimeoutException('wait-timeout exceeded before a terminal event');
        }
    }

    /** Short pause between reconnect attempts, never sleeping past the deadline. */
    private function backoff(int $attempt, ?float $deadline): void
    {
        $ms = self::RECONNECT_BACKOFF_MS[min($attempt - 1, count(self::RECONNECT_BACKOFF_MS) - 1)];
        if ($deadline !== null) {
            $ms = min($ms, max(0, (int) (($deadline - microtime(true)) * 1000)));
        }
        if ($ms > 0) {
            usleep($ms * 1000);
        }
        $this->assertDeadline($deadline);
    }

    private function withFromSeq(string $path, int $seq): string
    {
        $path = (string) preg_replace('/([?&])from=\d+(&|$)/', '$1', $path);
        $path = rtrim($path, '?&');
        $sep = str_contains($path, '?') ? '&' : '?';
        return $path . $sep . "from=$seq";
    }
}
