<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Defaults
{
    public function __construct(
        public int $port = 0,
    ) {}
}
