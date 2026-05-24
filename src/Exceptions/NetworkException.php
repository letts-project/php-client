<?php
declare(strict_types=1);

namespace Letts\Exceptions;

final class NetworkException extends LettsException
{
    public function __construct(
        private readonly string $host,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[$host] $message", 0, $previous);
    }

    public function getHost(): string
    {
        return $this->host;
    }
}
