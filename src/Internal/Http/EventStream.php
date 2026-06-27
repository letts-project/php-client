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
 * than through a blocking fread() wrapper. That matters for quiet missions:
 * while nothing is emitted, the poll yields timeout chunks that we skip
 * silently — a long silence is normal for the events endpoint, not an error
 * (the fread path would surface each idle period as a PHP warning). The poll
 * cadence also bounds how late a wait deadline can fire.
 *
 * The status is read off the first chunk, never via a blocking accessor before
 * the poll loop (that would stall ~30s on a quiet mission, whose 200 headers
 * dugdale defers until the first event, and burn the reconnect budget): 4xx
 * maps to the Letts exception hierarchy (definitive); 5xx and transport drops
 * consume the reconnect budget with a short backoff between attempts.
 */
final class EventStream
{
    private const POLL_SECONDS = 1.0;
    private const RECONNECT_BACKOFF_MS = [100, 500, 2000];

    public function __construct(private readonly HttpTransport $transport) {}

    /**
     * Follow with automatic reconnect from last_seq on EOF before terminal
     * event. Caller's callback returns false to stop (e.g. on `done`); if the
     * stream closes without a stop signal, we GET again with ?from=<lastSeq>
     * appended (existing query params preserved).
     *
     * Reconnect budget: maxReconnects per follow() invocation, counting only
     * consecutive *unproductive* connections — a drop after new events were
     * delivered resets the budget, so long streams over restartable
     * connections don't exhaust a small cap.
     *
     * @param \Closure(Event): bool $onEvent
     */
    public function follow(string $path, \Closure $onEvent, int $maxReconnects = 3, ?float $deadline = null): void
    {
        $lastSeq = 0;
        $lastResetSeq = 0;
        $reconnects = 0;
        $url = $path;
        while (true) {
            if ($this->streamOnce($url, $onEvent, $lastSeq, $deadline)) {
                return; // callback consumed its terminal event
            }
            // Disconnected (or clean EOF) before a stop signal.
            $this->assertDeadline($deadline);
            if ($lastSeq > $lastResetSeq) {
                $reconnects = 0;
                $lastResetSeq = $lastSeq;
            }
            if (++$reconnects > $maxReconnects) {
                throw new NetworkException(
                    $this->transport->host(),
                    "event stream reconnect budget exhausted ($maxReconnects)",
                );
            }
            $this->backoff($reconnects, $deadline);
            $url = $this->withFromSeq($path, $lastSeq);
        }
    }

    /**
     * Open one connection and pump events. Returns true when the callback
     * stopped the iteration, false when the connection ended first (the
     * caller decides about reconnecting). Definitive HTTP refusals (4xx) and
     * an elapsed deadline abort the whole follow with an exception.
     */
    private function streamOnce(string $url, \Closure $onEvent, int &$lastSeq, ?float $deadline): bool
    {
        $response = $this->transport->streamRequest('GET', $url);

        // Do NOT call $response->getStatusCode() before the poll loop: dugdale
        // defers the 200 header flush until the first event, so a quiet mission
        // (a long ffmpeg step that emits nothing for minutes) would block that
        // accessor until the 30s request-inactivity timeout fires — returning a
        // needless reconnect every ~30s and exhausting the reconnect budget. We
        // poll instead (idle yields timeout chunks that keep the connection
        // alive) and read the status off the first chunk, once headers arrive.
        $errStatus = null;
        $buf = '';
        try {
            foreach ($this->transport->streamChunks($response, self::POLL_SECONDS) as $chunk) {
                if ($chunk->isTimeout()) {
                    // Idle poll wakeup — the mission is just quiet.
                    $this->assertDeadline($deadline, $response);
                    continue;
                }
                if ($chunk->isFirst()) {
                    // Headers are in now — getStatusCode() no longer blocks.
                    $status = $response->getStatusCode();
                    if ($status >= 500) {
                        $response->cancel();
                        return false; // transient server error — reconnectable
                    }
                    if ($status >= 400) {
                        // Auth/gone/not-found: the body is an error document, not
                        // a stream. Collect it from the chunks below (it's tiny
                        // and the connection closes right after), then map+throw.
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
                        return true;
                    }
                }
                $this->assertDeadline($deadline, $response);
            }
            if ($errStatus !== null) {
                // Definitive HTTP refusal — abort the whole follow (not a
                // TransportException, so it escapes the catch below).
                $response->cancel();
                throw HttpTransport::mapError($errStatus, $buf);
            }
        } catch (TransportExceptionInterface) {
            $response->cancel();
            return false; // connect failure / dropped mid-stream — reconnectable
        }
        return false; // clean EOF without a stop signal — reconnectable
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
