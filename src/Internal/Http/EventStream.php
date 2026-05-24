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
 * HTTP errors (4xx) are mapped to the Letts exception hierarchy before any
 * body is interpreted as a stream; 5xx and transport drops consume the
 * reconnect budget with a short backoff between attempts.
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
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            $response->cancel();
            return false; // connect failure — reconnectable
        }
        if ($status >= 500) {
            $response->cancel();
            return false; // transient server error — reconnectable
        }
        if ($status >= 400) {
            // Auth/gone/not-found: the body is an error document, not a
            // stream, and asking again will not change the answer.
            try {
                $body = $response->getContent(false);
            } catch (TransportExceptionInterface) {
                $body = '';
            }
            throw HttpTransport::mapError($status, $body);
        }

        $buf = '';
        try {
            foreach ($this->transport->streamChunks($response, self::POLL_SECONDS) as $chunk) {
                if ($chunk->isTimeout()) {
                    // Idle poll wakeup — the mission is just quiet.
                    $this->assertDeadline($deadline, $response);
                    continue;
                }
                if ($chunk->isFirst()) {
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
        } catch (TransportExceptionInterface) {
            $response->cancel();
            return false; // dropped mid-stream — reconnectable
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
