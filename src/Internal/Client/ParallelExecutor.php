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
use Letts\Exceptions\LettsException;
use Letts\Exceptions\NetworkException;
use Letts\Exceptions\StagingException;
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
        /** @var array<int, HostResult> */
        $results = [];

        // Phase 1: prepare each job (resolve target, upload files, build body)
        // and issue its /v1/dispatch POST as a LAZY response. Creating every
        // request before reading any lets curl_multi run them concurrently in
        // phase 2, so one slow or unreachable host no longer blocks the others
        // — the POSTs used to be sent (and retried) strictly one host at a time.
        /** @var array<int, array{host: string, mid: string, post: ResponseInterface}> */
        $posts = [];
        foreach ($jobs as $i => $j) {
            $hostHint = (string) ($j['host'] ?? '');
            // Assign the id pre-flight so a per-host launch failure carries it.
            $mid = \Letts\Internal\IdsUuidV7::generate();
            try {
                $prep = (new DispatchExecutor($this->client))->prepare(
                    $j['route'] ?? null,
                    $j['host'] ?? null,
                    $j['match'] ?? [],
                    $j['lane'] ?? null,
                    (string) $j['mission'],
                    $j['input'] ?? [],
                    $j['files'] ?? [],
                    $j['timeout'] ?? null,
                    $mid,
                );
                $post = $this->client->rawTransportFor($prep['host'], Scope::Dispatch)
                    ->requestLazyJson('POST', '/v1/dispatch', $prep['body'], ['Idempotency-Key' => $prep['missionId']]);
                $posts[$i] = ['host' => $prep['host'], 'mid' => $prep['missionId'], 'post' => $post];
            } catch (AuthException|BadRequestException|ConflictException|BackpressureException|StagingException|NetworkException|DispatchException $e) {
                $results[$i] = new HostResult($hostHint, null, $this->toHostError($e));
                $this->reportJobFailure($e, 'dispatch', $j, $mid);
            }
            // ConfigException / NoMatchingDugdaleException are intentionally NOT
            // caught — they are config-level and propagate (see design §5.2).
        }

        // Phase 2: complete the dispatch POSTs (the first read drives them all
        // concurrently over curl_multi) and open each success's event stream.
        /** @var array<int, array{host: string, mid: string, response: ResponseInterface, buf: string, done: ?Event}> */
        $followers = [];
        foreach ($posts as $i => $p) {
            try {
                $data = $this->client->rawTransportFor($p['host'], Scope::Dispatch)->completeJson($p['post']);
                $mid = (string) ($data['mission_id'] ?? $p['mid']);
                $resp = $this->client->rawTransportFor($p['host'], Scope::Dispatch)
                    ->streamRequest('GET', "/v1/missions/$mid/events?follow=true");
                $followers[$i] = [
                    'host' => $p['host'], 'mid' => $mid,
                    'response' => $resp, 'buf' => '', 'done' => null,
                ];
            } catch (AuthException|BadRequestException|ConflictException|BackpressureException|StagingException|NetworkException|DispatchException $e) {
                $results[$i] = new HostResult($p['host'], null, $this->toHostError($e));
                $this->reportJobFailure($e, 'dispatch', $jobs[$i], $p['mid']);
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
                    // Mission launched but its stream dropped before a terminal
                    // event — a stream-phase launch failure (no reconnect here).
                    $err = new NetworkException($f['host'], 'event stream ended without a terminal event');
                    $results[$i] = new HostResult($f['host'], null, new HostError('network', $err->getMessage(), null));
                    $this->reportJobFailure($err, 'stream', $jobs[$i], $f['mid']);
                }
            }
        }

        ksort($results);
        return array_values($results);
    }

    /** Maps a dispatch-phase exception to its HostError kind. */
    private function toHostError(LettsException $e): HostError
    {
        return match (true) {
            $e instanceof AuthException         => new HostError('auth', $e->getMessage(), 401),
            $e instanceof BadRequestException   => new HostError('bad_request', $e->getMessage(), 400),
            $e instanceof ConflictException     => new HostError('conflict', $e->getMessage(), 409),
            $e instanceof BackpressureException => new HostError('backpressure', $e->getMessage(), 503),
            $e instanceof StagingException      => new HostError('staging', $e->getMessage(), null),
            // NetworkException | DispatchException and any other dispatch error.
            default                             => new HostError('network', $e->getMessage(), null),
        };
    }

    /**
     * Notify the Client failure observer for one failed fan-out job. timeout
     * HostErrors do not come here — the mission is still running (see §5).
     *
     * @param array<string, mixed> $job
     */
    private function reportJobFailure(LettsException $e, string $phase, array $job, ?string $missionId): void
    {
        $this->client->reportLaunchFailure(
            $e, 'runParallel', $phase, (string) $job['mission'],
            $job['route'] ?? null, $job['host'] ?? null, $job['match'] ?? [], $job['lane'] ?? null,
            $job['input'] ?? [], $job['files'] ?? [], $job['timeout'] ?? null, $missionId,
        );
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
