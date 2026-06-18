<?php
declare(strict_types=1);

namespace Letts\Config;

/**
 * Validates names. Mirrors Go internal/lettsconfig validators.
 */
final class NameValidator
{
    public static function isDugdaleId(string $s): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $s);
    }

    /**
     * Alias keys are lookup handles, not dugdale ids, so they may also lead
     * with a digit (e.g. a numeric server number, `5: s5`). Same
     * charset and length bound as a dugdale id otherwise.
     */
    public static function isAliasKey(string $s): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $s);
    }

    public static function isLaneName(string $s): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $s);
    }

    public static function isLabel(string $s): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $s);
    }

    public static function isRouteName(string $s): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $s);
    }

    public static function isTemplateName(string $s): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $s);
    }
}
