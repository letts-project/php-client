<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

use Letts\Exceptions\AuthException;
use Letts\Exceptions\BackpressureException;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\ConflictException;
use Letts\Exceptions\DispatchException;
use Letts\Exceptions\NetworkException;

/**
 * Decorator around HttpTransport that retries ambiguous network/5xx failures
 * up to maxAttempts with jitter backoff. 4xx propagates immediately.
 * Re-buffering of body is automatic because we just call jsonRequest again
 * with the same array arg.
 */
final class RetryClient
{
    /**
     * @param list<int> $backoffMs
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly int $maxAttempts = 3,
        private readonly array $backoffMs = [100, 500, 2000],
    ) {}

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     * @return array<string, mixed>
     */
    public function jsonRequest(
        string $method, string $path,
        ?array $body = null,
        array $extraHeaders = [],
    ): array {
        $attempts = max(1, $this->maxAttempts);
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $this->transport->jsonRequest($method, $path, $body, $extraHeaders);
            } catch (BadRequestException|AuthException|ConflictException|BackpressureException $e) {
                throw $e;
            } catch (DispatchException|NetworkException $e) {
                if (!self::isRetryable($e)) {
                    throw $e;
                }
                $last = $e;
                if ($i + 1 < $attempts) {
                    $this->sleepWithJitter($this->backoffFor($i));
                }
            }
        }
        throw $last ?? new DispatchException('retry exhausted');
    }

    /**
     * Only ambiguous failures are retryable: network errors, and 5xx
     * DispatchExceptions. A DispatchException carrying a 4xx status code
     * (404/410/412/413/429 …) is a definitive client-side condition — retrying
     * is wasteful and, for 429, harmful. The HTTP status is stored
     * as the exception code by HttpTransport::mapError.
     */
    private static function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof NetworkException) {
            return true;
        }
        if ($e instanceof DispatchException) {
            $status = $e->getCode();
            return $status === 0 || $status >= 500;
        }
        return false;
    }

    /**
     * Run an arbitrary operation under the same retry policy as jsonRequest:
     * retry DispatchException/NetworkException up to maxAttempts with jitter
     * backoff; 4xx (BadRequest/Auth/Conflict/Backpressure) propagate
     * immediately. Used for the staging HEAD-then-PUT workflow, which is not a
     * plain JSON request.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function run(\Closure $fn): mixed
    {
        $attempts = max(1, $this->maxAttempts);
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (BadRequestException|AuthException|ConflictException|BackpressureException $e) {
                throw $e;
            } catch (DispatchException|NetworkException $e) {
                if (!self::isRetryable($e)) {
                    throw $e;
                }
                $last = $e;
                if ($i + 1 < $attempts) {
                    $this->sleepWithJitter($this->backoffFor($i));
                }
            }
        }
        throw $last ?? new DispatchException('retry exhausted');
    }

    private function backoffFor(int $attempt): int
    {
        $cadence = $this->backoffMs ?: [100, 500, 2000];
        return $cadence[min($attempt, count($cadence) - 1)];
    }

    private function sleepWithJitter(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        $bound = max(1, intdiv($ms, 5));
        $jitter = random_int(-$bound, $bound);
        usleep(max(0, ($ms + $jitter)) * 1000);
    }
}
