<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Selector
{
    /** @param list<string> $match */
    public function __construct(
        public array $match = [],
    ) {}
}
