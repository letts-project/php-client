<?php
declare(strict_types=1);

namespace Letts\Exceptions;

use Letts\Result\RunResult;

final class MissionFailedException extends LettsException
{
    /** @param array<string, mixed>|null $failDetails */
    public function __construct(
        private readonly string $outcome,
        private readonly ?string $reason,
        private readonly ?string $failMessage,
        private readonly ?array $failDetails,
        private readonly ?RunResult $result,
    ) {
        parent::__construct('mission failed: ' . ($failMessage ?? '(no message)'));
    }

    public function getOutcome(): string { return $this->outcome; }
    public function getReason(): ?string { return $this->reason; }
    public function getFailMessage(): ?string { return $this->failMessage; }
    /** @return array<string, mixed>|null */
    public function getFailDetails(): ?array { return $this->failDetails; }
    public function getResult(): ?RunResult { return $this->result; }
}
