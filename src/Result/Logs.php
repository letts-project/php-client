<?php
declare(strict_types=1);

namespace Letts\Result;

final readonly class Logs
{
    public function __construct(
        public string $stdout = '',
        public string $stderr = '',
        public bool $stdoutTruncated = false,
        public bool $stderrTruncated = false,
    ) {}

    /** @param array<string, mixed> $d */
    public static function fromApiResponse(array $d): self
    {
        return new self(
            stdout: (string) ($d['stdout'] ?? ''),
            stderr: (string) ($d['stderr'] ?? ''),
            stdoutTruncated: (bool) ($d['stdout_truncated'] ?? false),
            stderrTruncated: (bool) ($d['stderr_truncated'] ?? false),
        );
    }
}
