<?php
declare(strict_types=1);

namespace Letts\Internal\Http;

/**
 * Internal carrier for raw HTTP failure details before mapping to the public
 * Letts\Exceptions\* hierarchy. Used by HttpTransport and RetryClient.
 */
final class HttpError extends \RuntimeException
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $serverMessage,
        public readonly ?array $body = null,
    ) {
        parent::__construct(sprintf('HTTP %d %s: %s', $status, $errorCode, $serverMessage));
    }
}
