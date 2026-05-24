<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class LaneCfg
{
    public function __construct(
        public int $concurrency,
        public bool $paused = false,
    ) {}
}
