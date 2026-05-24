<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\MissingEnvException;

/**
 * Replaces ${VAR} occurrences in strings using an injectable lookup. Mirrors
 * Go internal/lettsconfig SubstituteEnv. The lookup function returns null
 * for missing vars, which surfaces as MissingEnvException.
 */
final class EnvSubstitutor
{
    private const RE_ENV_VAR = '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /** @param \Closure(string): ?string $lookup */
    public function __construct(private \Closure $lookup) {}

    public function substitute(string $s): string
    {
        $missing = null;
        $out = preg_replace_callback(
            self::RE_ENV_VAR,
            function (array $m) use (&$missing): string {
                $val = ($this->lookup)($m[1]);
                if ($val === null) {
                    $missing ??= $m[1];
                    return '';
                }
                return $val;
            },
            $s,
        );
        if ($missing !== null) {
            throw new MissingEnvException($missing);
        }
        return (string) $out;
    }
}
