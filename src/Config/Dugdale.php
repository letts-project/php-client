<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Dugdale
{
    /**
     * @param list<string>           $labels
     * @param array<string, LaneCfg> $lanes
     * @param list<string>           $nullifiedLanes  lanes set to `null` in YAML
     *                               (delete-from-template markers)
     */
    public function __construct(
        public string $id,
        public string $host = '',
        public int $port = 0,
        public string $url = '',
        public string $proxy = '',
        public string $extends = '',
        public string $missionDir = '',
        public Runtime $runtime = new Runtime(),
        public array $labels = [],
        public string $token = '',
        public string $adminToken = '',
        public string $execToken = '',
        public array $lanes = [],
        public array $nullifiedLanes = [],
    ) {}

    public function hasLane(string $lane): bool
    {
        return isset($this->lanes[$lane]);
    }

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, strict: true);
    }
}
