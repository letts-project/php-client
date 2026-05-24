<?php
declare(strict_types=1);

namespace Letts\Internal\Client;

use Letts\Client;
use Letts\Config\Scope;
use Letts\Exceptions\AuthException;
use Letts\Exceptions\BackpressureException;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\ConflictException;
use Letts\Exceptions\DispatchException;
use Letts\Exceptions\NetworkException;
use Letts\Internal\Http\Event;
use Letts\Result\HostError;
use Letts\Result\HostResult;
use Letts\Result\Logs;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Runs many jobs concurrently. Each job is dispatched, then ALL of their
 * `/events` streams are followed in a single cooperative loop multiplexed over
 * the shared Symfony HttpClient (curl_multi), so the wall-clock is the slowest
 * job — not the sum. Results preserve the input job order.
 *
 * Per-host transport/auth/config errors during dispatch become HostResult
 * errors (config/no-match errors propagate).
 *
 * Parallel-mode caveat: unlike single run(), a stream that drops mid-flight is
 * NOT reconnected here — the affected job surfaces a network HostError. Fan-out
 * targets short ctl-style missions; use run() when mid-stream resilience matters.
 */
final class ParallelExecutor
{
    public function __construct(private readonly Client $client) {}

    private const POLL_SECONDS = 1.0;

    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<HostResult>
     */
    public function runParallel(array $jobs, ?string $waitTimeout = null): array
    {
        $deadline = $waitTimeout !== null
            ? microtime(true) + RunExecutor::parseDuration($waitTimeout)
            : null;
        /** @var array<int, array{host: string, mid: string, response: ResponseInterface, buf: string, done: ?Event}> */
        $followers = [];
        /** @var array<int, HostResult> */
        $results = [];

        foreach ($jobs as $i => $j) {
            $hostHint = (string) ($j['host'] ?? '');
            try {
                $dr = (new DispatchExecutor($this->client))->dispatch(
                    $j['route'] ?? null,
                    $j['host'] ?? null,
                    $j['match'] ?? [],
                    $j['lane'] ?? null,
                    (string) $j['mission'],
                    $j['input'] ?? [],
                    $j['files'] ?? [],
                    $j['timeout'] ?? null,
                    null,
                );
                $resp = $this->client->rawTransportFor($dr['host'], Scope::Dispatch)
                    ->streamRequest('GET', "/v1/missions/{$dr['missionId']}/events?follow=true");
                $followers[$i] = [
                    'host' => $dr['host'], 'mid' => $dr['missionId'],
                    'response' => $resp, 'buf' => '', 'done' => null,
                ];
            } catch (AuthException $e) {
                $results[$i] = new HostResult($hostHint, null, new HostError('auth', $e->getMessage(), 401));
            } catch (BadRequestException $e) {
                $results[$i] = new HostResult($hostHint, null, new HostError('bad_request', $e->getMessage(), 400));
            } catch (ConflictException $e) {
                $results[$i] = new HostResult($hostHint, null, new HostError('conflict', $e->getMessage(), 409));
            } catch (BackpressureException $e) {
                $results[$i] = new HostResult($hostHint, null, new HostError('backpressure', $e->getMessage(), 503));
            } catch (NetworkException|DispatchException $e) {
                $results[$i] = new HostResult($hostHint, null, new HostError('network', $e->getMessage(), null));
            }
        }

        if ($followers !== []) {
            $this->followAll($followers, $deadline);
            $timedOut = $deadline !== null && microtime(true) >= $deadline;
            foreach ($followers as $i => $f) {
                if ($f['done'] !== null) {
                    $rr = RunExecutor::buildResult($f['host'], $f['mid'], $f['done'], new Logs());
                    $results[$i] = new HostResult($f['host'], $rr, null);
                } elseif ($timedOut) {
                    // The mission may well still be running on the daemon;
                    // we only stopped waiting for it.
                    $results[$i] = new HostResult(
                        $f['host'], null,
                        new HostError('timeout', 'wait-timeout exceeded before a terminal event', null),
                    );
                } else {
                    $results[$i] = new HostResult(
                        $f['host'], null,
                        new HostError('network', 'event stream ended without a terminal event', null),
                    );
                }
            }
        }

        ksort($results);
        return array_values($results);
    }

    /**
     * Multiplex the open event streams until each follower hits its `done`
     * event (or its stream errors/ends, or the deadline passes). Mutates
     * $followers in place; unfinished streams are cancelled on deadline so
     * their connections are released.
     *
     * @param array<int, array{host: string, mid: string, response: ResponseInterface, buf: string, done: ?Event}> $followers
     */
    private function followAll(array &$followers, ?float $deadline = null): void
    {
        $indexByResponseId = [];
        $responses = [];
        foreach ($followers as $i => $f) {
            $indexByResponseId[spl_object_id($f['response'])] = $i;
            $responses[] = $f['response'];
        }

        // A poll timeout is only needed to honor a wait deadline on otherwise
        // idle streams; without one, block normally so a chunk arriving the
        // same instant as a poll tick can't race into a spurious timeout.
        $poll = $deadline !== null ? self::POLL_SECONDS : null;
        foreach ($this->client->httpClient()->stream($responses, $poll) as $response => $chunk) {
            $i = $indexByResponseId[spl_object_id($response)] ?? null;
            if ($i === null || $followers[$i]['done'] !== null) {
                continue;
            }
            try {
                if ($chunk->isTimeout()) {
                    // Idle poll tick: only meaningful for the deadline check.
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        $this->cancelUnfinished($followers);
                        return;
                    }
                    continue;
                }
                if ($chunk->isFirst()) {
                    continue;
                }
                $content = $chunk->getContent();
            } catch (\Throwable) {
                // HTTP/transport error on this stream: no reconnect in parallel
                // mode — leave done=null so the caller records a network error.
                continue;
            }
            if ($content === '') {
                continue;
            }
            $followers[$i]['buf'] .= $content;
            while (($nl = strpos($followers[$i]['buf'], "\n")) !== false) {
                $line = substr($followers[$i]['buf'], 0, $nl);
                $followers[$i]['buf'] = substr($followers[$i]['buf'], $nl + 1);
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }
                $ev = Event::fromJson($data);
                if ($ev->event === 'done') {
                    $followers[$i]['done'] = $ev;
                    $response->cancel(); // free the connection; stop reading this stream
                    break;
                }
            }
        }
    }

    /** @param array<int, array{response: ResponseInterface, done: ?Event}> $followers */
    private function cancelUnfinished(array $followers): void
    {
        foreach ($followers as $f) {
            if ($f['done'] === null) {
                $f['response']->cancel();
            }
        }
    }
}
