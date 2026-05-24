<?php
declare(strict_types=1);

namespace Letts\Result;

final readonly class HostError
{
    public function __construct(
        public string $kind,
        public string $message,
        public ?int $httpStatus = null,
        public ?string $errorCode = null,
    ) {}
}
