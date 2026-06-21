<?php
declare(strict_types=1);

namespace Letts\Result;

/**
 * Describes a task launch that failed before producing a result — passed to the
 * Client failure observer (Client::withFailureObserver / onLaunchFailure). Carries
 * enough of the original launch attempt to log it and, when retryable, recreate it.
 *
 * $host is the RAW addressing argument the caller passed (not the resolved dugdale
 * id); the resolved dugdale for network failures is on $exception->getHost(). $phase
 * is 'dispatch' (never started — re-dispatchable) or 'stream' (mission is already
 * running on the daemon, only the client event stream dropped — reconnect, do not
 * re-dispatch).
 */
final readonly class LaunchFailure
{
    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $files
     * @param list<string>          $match
     */
    public function __construct(
        public \Letts\Exceptions\LettsException $exception,
        public bool $retryable,
        public string $method,
        public string $phase,
        public string $mission,
        public ?string $route,
        public int|string|null $host,
        public array $match,
        public ?string $lane,
        public array $input,
        public array $files,
        public ?string $timeout,
        public ?string $missionId,
    ) {}
}
