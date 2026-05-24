<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Route
{
    public function __construct(
        public string $host,
        public string $lane,
    ) {}
}
