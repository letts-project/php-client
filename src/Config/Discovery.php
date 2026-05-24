<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;

/**
 * Discovers letts.yaml via the same cascade as Go internal/lettsconfig,
 * so the PHP lib finds the same file the `letts` CLI does:
 *   1. $LETTS_CONFIG env — if set, the file MUST exist (hard error otherwise).
 *   2. $cwd/letts.yaml
 *   3. $XDG_CONFIG_HOME/letts/letts.yaml (only when XDG_CONFIG_HOME is set)
 *   4. $HOME/.letts/letts.yaml
 *   5. /etc/letts/letts.yaml
 * First existing file wins.
 */
final class Discovery
{
    /** @param \Closure(string): ?string $envLookup */
    public static function find(
        \Closure $envLookup,
        ?string $cwd = null,
        ?string $home = null,
    ): string {
        $cwd ??= getcwd() ?: '/';
        $home ??= $envLookup('HOME') ?? posix_getpwuid(posix_geteuid())['dir'] ?? '/';

        $env = $envLookup('LETTS_CONFIG');
        if ($env !== null && $env !== '') {
            // Set-but-missing is a hard error (mirrors Go discovery.go); do NOT
            // silently fall through to the cascade.
            if (!is_file($env)) {
                throw new ConfigException("config file \"$env\" does not exist (from \$LETTS_CONFIG)");
            }
            return $env;
        }

        $candidates = ["$cwd/letts.yaml"];

        $xdg = $envLookup('XDG_CONFIG_HOME');
        if ($xdg !== null && $xdg !== '') {
            $candidates[] = "$xdg/letts/letts.yaml";
        }

        $candidates[] = "$home/.letts/letts.yaml";
        $candidates[] = '/etc/letts/letts.yaml';

        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        throw new ConfigException(
            'letts.yaml not found; searched: ' . implode(', ', $candidates),
        );
    }
}
