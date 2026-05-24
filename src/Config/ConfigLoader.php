<?php
declare(strict_types=1);

namespace Letts\Config;

/**
 * Composite entry point: discover → parse → strict-validate → extends-merge
 * → name-validate. Returns a fully resolved Config ready for HostResolver /
 * TokenResolver usage.
 */
final class ConfigLoader
{
    public static function loadFromPath(string $path): Config
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \Letts\Exceptions\ConfigException("cannot read $path");
        }
        $parsed = ConfigParser::parse($raw);
        $merged = ExtendsMerger::merge($parsed);
        ConfigValidator::validate($merged);
        return $merged;
    }

    /** @param \Closure(string): ?string $envLookup */
    public static function loadDefault(\Closure $envLookup): Config
    {
        $path = Discovery::find($envLookup);
        return self::loadFromPath($path);
    }
}
