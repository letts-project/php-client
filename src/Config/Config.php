<?php
declare(strict_types=1);

namespace Letts\Config;

/**
 * Root DTO for letts.yaml after parsing, merge, and validation. Env substitution is
 * still deferred — performed lazily in HostResolver/TokenResolver.
 */
final readonly class Config
{
    /**
     * @param array<string, Route>    $routes
     * @param array<string, string>   $aliases
     * @param array<string, Template> $templates
     * @param list<Dugdale>           $dugdales
     */
    public function __construct(
        public Auth $auth = new Auth(),
        public Defaults $defaults = new Defaults(),
        public Selector $selector = new Selector(),
        public array $routes = [],
        public array $aliases = [],
        public array $templates = [],
        public array $dugdales = [],
    ) {}

    public function findDugdale(string $id): ?Dugdale
    {
        foreach ($this->dugdales as $d) {
            if ($d->id === $id) {
                return $d;
            }
        }
        return null;
    }
}
