<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;

/**
 * Validates a parsed Config: regex checks on all names, alias-key collision
 * with existing dugdale ids, and alias value checks. Mirrors Go-side
 * internal/lettsconfig.Validate.
 */
final class ConfigValidator
{
    public static function validate(Config $c): void
    {
        if ($c->defaults->port < 0 || $c->defaults->port > 65535) {
            throw new ConfigException("defaults.port {$c->defaults->port} out of range (0-65535)");
        }
        $seenIds = [];
        foreach ($c->dugdales as $i => $d) {
            if (!NameValidator::isDugdaleId($d->id)) {
                throw new ConfigException("dugdales[$i]: invalid dugdale id \"$d->id\"");
            }
            if ($d->port < 0 || $d->port > 65535) {
                throw new ConfigException("dugdales[$i]: port {$d->port} out of range (0-65535)");
            }
            self::validateProxy($d->proxy, "dugdales[$i]");
            if (isset($seenIds[$d->id])) {
                throw new ConfigException("dugdales[$i]: duplicate id \"$d->id\"");
            }
            $seenIds[$d->id] = true;

            foreach (array_keys($d->lanes) as $lane) {
                if (!NameValidator::isLaneName((string) $lane)) {
                    throw new ConfigException("dugdales[$i].lanes: invalid lane name \"$lane\"");
                }
            }
            foreach ($d->labels as $label) {
                if (!NameValidator::isLabel($label)) {
                    throw new ConfigException("dugdales[$i].labels: invalid label \"$label\"");
                }
            }
        }

        foreach ($c->templates as $name => $t) {
            if (!NameValidator::isTemplateName((string) $name)) {
                throw new ConfigException("templates[\"$name\"]: invalid template name");
            }
            self::validateProxy($t->proxy, "templates[\"$name\"]");
            foreach (array_keys($t->lanes) as $lane) {
                if (!NameValidator::isLaneName((string) $lane)) {
                    throw new ConfigException("templates[\"$name\"].lanes: invalid lane name \"$lane\"");
                }
            }
            foreach ($t->labels as $label) {
                if (!NameValidator::isLabel($label)) {
                    throw new ConfigException("templates[\"$name\"].labels: invalid label \"$label\"");
                }
            }
        }

        foreach (array_keys($c->routes) as $name) {
            if (!NameValidator::isRouteName((string) $name)) {
                throw new ConfigException("routes[\"$name\"]: invalid route name");
            }
        }

        foreach ($c->aliases as $key => $val) {
            if (!NameValidator::isAliasKey((string) $key)) {
                throw new ConfigException("aliases[\"$key\"]: invalid alias key");
            }
            if (isset($seenIds[$key])) {
                throw new ConfigException("aliases[\"$key\"]: collides with existing dugdales[].id");
            }
            if ($val === '') {
                throw new ConfigException("aliases[\"$key\"]: empty value");
            }
            // Pure-literal values must match dugdale-id regex; values with ${VAR}
            // skip the regex check (resolved at runtime in HostResolver).
            if (!str_contains($val, '${') && !NameValidator::isDugdaleId($val)) {
                throw new ConfigException("aliases[\"$key\"] value: invalid dugdale id \"$val\"");
            }
        }
    }

    /**
     * Validates a per-dugdale (or per-template) proxy URL. Accepted schemes are
     * socks5, socks5h, http, and https. An empty value means "connect directly"
     * and a value carrying a ${VAR} placeholder is skipped here (it is checked
     * after env substitution at use), mirroring how alias values are handled.
     */
    private static function validateProxy(string $proxy, string $where): void
    {
        if ($proxy === '' || str_contains($proxy, '${')) {
            return;
        }
        $scheme = strtolower((string) parse_url($proxy, PHP_URL_SCHEME));
        if (!in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true)) {
            throw new ConfigException(
                "$where: proxy scheme must be socks5, socks5h, http, or https, got \"$proxy\"",
            );
        }
    }
}
