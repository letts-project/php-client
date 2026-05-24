<?php
declare(strict_types=1);

namespace Letts\Result;

/**
 * Mirrors the JSON object returned by GET /v1/missions/{id} and the items
 * inside GET /v1/missions. Field mapping matches daemon
 * handlers.buildMissionResponse exactly. Optional fields use
 * sensible PHP zero/null defaults because the daemon omits absent keys.
 */
final readonly class MissionInfo
{
    /**
     * @param array<string, mixed>|null $input
     * @param array<string, mixed>|null $failDetails
     * @param array<string, mixed>|null $return
     * @param list<array{role?: string, staging_id: string, sha256?: string, size?: int}> $inputs
     * @param array<string, array{staging_id: string, sha256?: string, size?: int}> $outputs
     */
    public function __construct(
        public string $missionId,
        public string $kind = '',
        public string $lane = '',
        public string $missionName = '',
        public string $displayName = '',
        public string $groupId = '',
        public string $status = '',
        public string $outcome = '',
        public ?int $exitCode = null,
        public ?string $signal = null,
        public ?string $failReason = null,
        public ?string $failMessage = null,
        public ?array $failDetails = null,
        public ?array $return = null,
        public ?array $input = null,
        public string $inputFingerprint = '',
        public int $pid = 0,
        public int $timeCreatedMs = 0,
        public int $timeStartedMs = 0,
        public int $timeFinishedMs = 0,
        public int $durationMs = 0,
        public int $timeoutMs = 0,
        public bool $truncatedStdout = false,
        public bool $truncatedStderr = false,
        public ?string $restartedFrom = null,
        public array $inputs = [],
        public array $outputs = [],
    ) {}

    /** @param array<string, mixed> $d */
    public static function fromApiResponse(array $d): self
    {
        return new self(
            missionId: (string) ($d['mission_id'] ?? ''),
            kind: (string) ($d['kind'] ?? ''),
            lane: (string) ($d['lane'] ?? ''),
            missionName: (string) ($d['mission_name'] ?? ''),
            displayName: (string) ($d['display_name'] ?? ''),
            groupId: (string) ($d['group_id'] ?? ''),
            status: (string) ($d['status'] ?? ''),
            outcome: (string) ($d['outcome'] ?? ''),
            exitCode: isset($d['exit_code']) ? (int) $d['exit_code'] : null,
            signal: isset($d['signal']) ? (string) $d['signal'] : null,
            failReason: isset($d['fail_reason']) ? (string) $d['fail_reason'] : null,
            failMessage: isset($d['fail_message']) ? (string) $d['fail_message'] : null,
            failDetails: $d['fail_details'] ?? null,
            return: $d['return'] ?? null,
            input: $d['input'] ?? null,
            inputFingerprint: (string) ($d['input_fingerprint'] ?? ''),
            pid: (int) ($d['pid'] ?? 0),
            timeCreatedMs: (int) ($d['time_created'] ?? 0),
            timeStartedMs: (int) ($d['time_started'] ?? 0),
            timeFinishedMs: (int) ($d['time_finished'] ?? 0),
            durationMs: (int) ($d['duration_ms'] ?? 0),
            timeoutMs: (int) ($d['timeout_ms'] ?? 0),
            truncatedStdout: (bool) ($d['truncated_stdout'] ?? false),
            truncatedStderr: (bool) ($d['truncated_stderr'] ?? false),
            restartedFrom: isset($d['restarted_from']) ? (string) $d['restarted_from'] : null,
            inputs: $d['inputs'] ?? [],
            outputs: $d['outputs'] ?? [],
        );
    }
}
