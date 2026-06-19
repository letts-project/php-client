<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Template
{
    /**
     * @param list<string>           $labels
     * @param array<string, LaneCfg> $lanes
     */
    public function __construct(
        public string $missionDir = '',
        public Runtime $runtime = new Runtime(),
        public array $labels = [],
        public string $token = '',
        public string $adminToken = '',
        public string $execToken = '',
        public string $proxy = '',
        public array $lanes = [],
    ) {}
}
