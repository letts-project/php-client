<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;

/**
 * Resolves a host argument (alias or dugdale id) to a real dugdale id present
 * in Config->dugdales. Mirrors Go internal/lettsconfig ResolveHost.
 */
final class HostResolver
{
    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly Config $config,
        private readonly EnvSubstitutor $env,
    ) {}

    public function resolve(int|string $host): string
    {
        $visited = [];
        // Accept a numeric server id (e.g. 5) and look it up as a string alias
        // key ("5"). PHP normalizes numeric string array keys back to int, so
        // a `5: ...` alias entry is matched either way.
        $cur = (string) $host;
        // Strict `<` so the cap is exactly MAX_DEPTH hops (mirrors Go, which
        // tightened this from `<=` to avoid allowing one extra hop).
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($this->config->findDugdale($cur) !== null) {
                return $cur;
            }
            if (!isset($this->config->aliases[$cur])) {
                throw new ConfigException("host \"$cur\" not found in aliases or dugdales[].id");
            }
            $resolved = $this->env->substitute($this->config->aliases[$cur]);
            if ($resolved === $cur) {
                throw new ConfigException("alias \"$cur\" is self-referential");
            }
            if (isset($visited[$cur])) {
                throw new ConfigException("alias cycle detected starting at \"$host\"");
            }
            $visited[$cur] = true;
            $cur = $resolved;
        }
        throw new ConfigException("alias chain from \"$host\" exceeds max depth " . self::MAX_DEPTH);
    }

    /** @return array{host: string, lane: string} */
    public function resolveRoute(string $route): array
    {
        $r = $this->config->routes[$route] ?? null;
        if ($r === null) {
            throw new ConfigException("route \"$route\" not found in letts.yaml");
        }
        return ['host' => $this->resolve($r->host), 'lane' => $r->lane];
    }
}
