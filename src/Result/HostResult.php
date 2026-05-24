<?php
declare(strict_types=1);

namespace Letts\Result;

final readonly class HostResult
{
    public function __construct(
        public string $host,
        public ?RunResult $result,
        public ?HostError $error,
    ) {}

    public function isReachable(): bool
    {
        return $this->error === null;
    }

    public function isSuccess(): bool
    {
        return $this->result?->isSuccess() ?? false;
    }
}
