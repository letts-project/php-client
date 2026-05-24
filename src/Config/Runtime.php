<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Runtime
{
    /** @param list<string> $commandTemplate */
    public function __construct(
        public string $missionPathTemplate = '',
        public array $commandTemplate = [],
        public bool $validateMissionFile = false,
    ) {}
}
