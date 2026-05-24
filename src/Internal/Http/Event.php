<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

/**
 * Decoded NDJSON event from /v1/missions/{id}/events. Holds the union of all
 * event types (queued/running/progress/done) — fields not relevant for a
 * given event are null.
 *
 * The done event carries:
 *   - time_finished (mapped to $timeFinished)
 *   - duration_ms (mapped to $durationMs)
 *   - outputs as a map: role → {staging_id, sha256, size}
 *
 * @param array<string, mixed>|null $return
 * @param array<string, mixed>|null $failDetails
 * @param array<string, array{staging_id: string, sha256: string, size: int}>|null $outputs
 */
final readonly class Event
{
    public function __construct(
        public int $seq,
        public string $event,
        public ?int $pid = null,
        public ?float $value = null,
        public ?string $message = null,
        public ?string $outcome = null,
        public ?int $exitCode = null,
        public ?string $signal = null,
        public ?string $failReason = null,
        public ?string $failMessage = null,
        public ?array $failDetails = null,
        public ?array $return = null,
        public ?array $outputs = null,
        public ?int $time = null,
        public ?int $timeFinished = null,
        public ?int $durationMs = null,
    ) {}

    /** @param array<string, mixed> $d */
    public static function fromJson(array $d): self
    {
        return new self(
            seq: (int) ($d['seq'] ?? 0),
            event: (string) ($d['event'] ?? ''),
            pid: isset($d['pid']) ? (int) $d['pid'] : null,
            value: isset($d['value']) ? (float) $d['value'] : null,
            message: isset($d['message']) ? (string) $d['message'] : null,
            outcome: isset($d['outcome']) ? (string) $d['outcome'] : null,
            exitCode: isset($d['exit_code']) ? (int) $d['exit_code'] : null,
            signal: isset($d['signal']) ? (string) $d['signal'] : null,
            failReason: isset($d['fail_reason']) ? (string) $d['fail_reason'] : null,
            failMessage: isset($d['fail_message']) ? (string) $d['fail_message'] : null,
            failDetails: $d['fail_details'] ?? null,
            return: $d['return'] ?? null,
            outputs: $d['outputs'] ?? null,
            time: isset($d['time']) ? (int) $d['time'] : null,
            timeFinished: isset($d['time_finished']) ? (int) $d['time_finished'] : null,
            durationMs: isset($d['duration_ms']) ? (int) $d['duration_ms'] : null,
        );
    }
}
