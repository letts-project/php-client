<?php
declare(strict_types=1);

namespace Letts\Result;

final readonly class RunResult
{
    /**
     * @param array<string, mixed>|null $failDetails
     * @param array<string, mixed>|null $return
     * @param array<string, array{staging_id?: string, size?: int, sha256?: string, path?: string}> $outputFiles
     */
    public function __construct(
        public string $host,
        public string $missionId,
        public string $outcome,
        public ?string $failReason,
        public ?string $failMessage,
        public ?array $failDetails,
        public ?array $return,
        public ?int $exitCode,
        public ?string $signal,
        public int $durationMs,
        public Logs $logs,
        public array $outputFiles,
    ) {}

    public function isSuccess(): bool
    {
        return $this->outcome === 'success';
    }

    /** @param array<string, mixed> $d */
    public static function fromApiResponse(string $host, array $d): self
    {
        return new self(
            host: $host,
            missionId: (string) ($d['mission_id'] ?? ''),
            outcome: (string) ($d['outcome'] ?? ''),
            failReason: isset($d['fail_reason']) ? (string) $d['fail_reason'] : null,
            failMessage: isset($d['fail_message']) ? (string) $d['fail_message'] : null,
            failDetails: $d['fail_details'] ?? null,
            return: $d['return'] ?? null,
            exitCode: isset($d['exit_code']) ? (int) $d['exit_code'] : null,
            signal: isset($d['signal']) ? (string) $d['signal'] : null,
            durationMs: (int) ($d['duration_ms'] ?? 0),
            logs: Logs::fromApiResponse($d['logs'] ?? []),
            outputFiles: $d['outputs'] ?? [],
        );
    }
}
