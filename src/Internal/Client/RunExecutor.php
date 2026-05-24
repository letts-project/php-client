<?php
declare(strict_types=1);

namespace Letts\Internal\Client;

use Letts\Client;
use Letts\Config\Scope;
use Letts\Exceptions\MissionFailedException;
use Letts\Exceptions\StagingException;
use Letts\Internal\Http\Event;
use Letts\Internal\Http\EventStream;
use Letts\Result\Logs;
use Letts\Result\RunResult;

/**
 * Wraps DispatchExecutor with NDJSON event following until terminal event,
 * then synthesizes a RunResult from the done event payload. throwOnFailure
 * controls whether a non-success outcome surfaces as MissionFailedException.
 */
final class RunExecutor
{
    public function __construct(private readonly Client $client) {}

    /**
     * @param list<string>          $match
     * @param array<string, mixed>  $input
     * @param array<string, string> $files
     */
    public function run(
        ?string $route, ?string $host, array $match,
        ?string $lane,
        string  $mission,
        array   $input,
        array   $files,
        ?string $timeout,
        ?string $waitTimeout,
        ?\Closure $onProgress,
        ?string $downloadOutputsTo,
        bool    $throwOnFailure,
        ?string $missionId,
        bool    $fetchLogs = false,
    ): RunResult {
        $dispatch = new DispatchExecutor($this->client);
        $dispatchResult = $dispatch->dispatch(
            $route, $host, $match, $lane, $mission, $input,
            $files, $timeout, $missionId,
        );
        $hostId = $dispatchResult['host'];
        $mid = $dispatchResult['missionId'];

        $rawT = $this->client->rawTransportFor($hostId, Scope::Dispatch);
        $stream = new EventStream($rawT);

        $doneEv = null;
        $deadline = null;
        if ($waitTimeout !== null) {
            $deadline = microtime(true) + self::parseDuration($waitTimeout);
        }
        $stream->follow(
            "/v1/missions/$mid/events?follow=true",
            function (Event $ev) use (&$doneEv, $onProgress): bool {
                if ($ev->event === 'progress' && $onProgress !== null) {
                    $onProgress($ev->value, $ev->message);
                }
                if ($ev->event === 'done') {
                    $doneEv = $ev;
                    return false;
                }
                return true;
            },
            maxReconnects: 3,
            deadline: $deadline,
        );

        if ($doneEv === null) {
            throw new \Letts\Exceptions\NetworkException($hostId, 'event stream stopped before a terminal event');
        }

        $logs = $fetchLogs ? $this->fetchLogs($hostId, $mid) : new Logs();
        $result = self::buildResult($hostId, $mid, $doneEv, $logs);

        if ($downloadOutputsTo !== null && $result->isSuccess()) {
            $this->downloadOutputs($hostId, $mid, $result, $downloadOutputsTo);
        }

        if ($throwOnFailure && !$result->isSuccess()) {
            throw new MissionFailedException(
                outcome: $result->outcome,
                reason: $result->failReason,
                failMessage: $result->failMessage,
                failDetails: $result->failDetails,
                result: $result,
            );
        }
        return $result;
    }

    /**
     * Fetch stdout/stderr via GET /v1/missions/{id}/output. The
     * done event does not carry logs, so this is an extra round-trip — done
     * only when the caller opts in via run(fetchLogs: true). Truncation flags
     * live on GET /v1/missions/{id}; we leave them false here to avoid a third
     * request. Failures degrade to empty logs rather than failing the run.
     */
    private function fetchLogs(string $host, string $mid): Logs
    {
        $t = $this->client->rawTransportFor($host, Scope::Dispatch);
        return new Logs(
            stdout: $this->fetchStream($t, $mid, 'stdout'),
            stderr: $this->fetchStream($t, $mid, 'stderr'),
        );
    }

    private function fetchStream(\Letts\Internal\Http\HttpTransport $t, string $mid, string $stream): string
    {
        try {
            return $t->rawRequest('GET', "/v1/missions/$mid/output?stream=$stream")->getContent();
        } catch (\Throwable) {
            return '';
        }
    }

    /** Build a RunResult from a terminal `done` event. Shared with ParallelExecutor. */
    public static function buildResult(string $host, string $mid, Event $ev, Logs $logs): RunResult
    {
        // The done event carries duration_ms and an
        // outputs map keyed by role with {staging_id, sha256, size}. Read
        // both directly from the event — no follow-up GET /v1/missions/{id}.
        $outputFiles = [];
        foreach (($ev->outputs ?? []) as $role => $meta) {
            $outputFiles[(string) $role] = [
                'staging_id' => (string) ($meta['staging_id'] ?? ''),
                'sha256' => (string) ($meta['sha256'] ?? ''),
                'size' => (int) ($meta['size'] ?? 0),
            ];
        }

        return new RunResult(
            host: $host, missionId: $mid, outcome: (string) $ev->outcome,
            failReason: $ev->failReason, failMessage: $ev->failMessage,
            failDetails: $ev->failDetails, return: $ev->return,
            exitCode: $ev->exitCode, signal: $ev->signal,
            durationMs: $ev->durationMs ?? 0,
            logs: $logs,
            outputFiles: $outputFiles,
        );
    }

    /** Shared with ParallelExecutor for waitTimeout parsing. */
    public static function parseDuration(string $d): float
    {
        if (preg_match('/^(\d+)(ms|s|m|h)$/', $d, $m)) {
            $n = (int) $m[1];
            return match ($m[2]) {
                'ms' => $n / 1000.0,
                's'  => (float) $n,
                'm'  => $n * 60.0,
                'h'  => $n * 3600.0,
            };
        }
        throw new \InvalidArgumentException("invalid duration: $d");
    }

    private function downloadOutputs(string $host, string $mid, RunResult $r, string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new StagingException("cannot create download dir: $dir");
        }
        $transport = $this->client->rawTransportFor($host, Scope::Dispatch);
        foreach ($r->outputFiles as $role => $meta) {
            $sid = (string) ($meta['staging_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            // The role becomes a local filename; refuse anything that could
            // land outside $dir, whatever the server claims.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $role)) {
                throw new StagingException("refusing to save output with unsafe role \"$role\"");
            }
            $this->downloadOne($transport, $sid, "$dir/$role", $meta);
        }
    }

    /**
     * Stream one staging artifact to disk. Output files routinely exceed the
     * PHP memory limit, so the body is written chunk-by-chunk into a .partial
     * file, hashed on the fly, verified against the size/sha256 announced in
     * the done event, and only then renamed into place. Any shortfall (disk
     * full, dropped transfer, content mismatch) surfaces as StagingException
     * with no half-written file left at the destination path.
     *
     * @param array{staging_id?: string, sha256?: string, size?: int} $meta
     */
    private function downloadOne(\Letts\Internal\Http\HttpTransport $t, string $sid, string $dest, array $meta): void
    {
        // buffer:false so the body is consumed straight to disk instead of
        // also being staged in Symfony's in-memory/temp buffer first.
        $response = $t->streamRequest('GET', "/v1/staging/$sid");
        try {
            $status = $response->getStatusCode();
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            throw new StagingException("download of staging $sid failed: " . $e->getMessage(), 0, $e);
        }
        if ($status >= 400) {
            $response->cancel();
            throw new StagingException("staging $sid not downloadable: HTTP $status");
        }
        $tmp = "$dest.partial";
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            throw new StagingException("cannot write $tmp");
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            try {
                foreach ($t->streamChunks($response) as $chunk) {
                    if ($chunk->isTimeout() || $chunk->isFirst()) {
                        continue;
                    }
                    $data = $chunk->getContent();
                    if ($data === '') {
                        continue;
                    }
                    hash_update($hash, $data);
                    if (fwrite($out, $data) !== strlen($data)) {
                        throw new StagingException("short write to $tmp (disk full?)");
                    }
                    $bytes += strlen($data);
                }
            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
                throw new StagingException("download of staging $sid failed: " . $e->getMessage(), 0, $e);
            }
            fclose($out);
            $out = null;

            $wantSize = (int) ($meta['size'] ?? 0);
            if ($wantSize > 0 && $bytes !== $wantSize) {
                throw new StagingException("staging $sid: downloaded $bytes bytes, expected $wantSize");
            }
            $wantSha = (string) ($meta['sha256'] ?? '');
            $gotSha = hash_final($hash);
            if ($wantSha !== '' && !hash_equals($wantSha, $gotSha)) {
                throw new StagingException("staging $sid: sha256 mismatch (got $gotSha, expected $wantSha)");
            }
            if (!@rename($tmp, $dest)) {
                throw new StagingException("cannot move downloaded file into place: $dest");
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
            throw $e;
        }
    }
}
