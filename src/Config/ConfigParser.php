<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses raw letts.yaml bytes into a Config tree. No extends merge, no env
 * substitution, no validation — those happen in subsequent stages
 * (ConfigMerger, env substitutor, NameValidator).
 */
final class ConfigParser
{
    public static function parse(string $yaml): Config
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new ConfigException('yaml parse: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new ConfigException('yaml root must be a mapping');
        }
        return self::buildConfig($data);
    }

    /** @param array<string, mixed> $data */
    private static function buildConfig(array $data): Config
    {
        self::checkKeys($data, ['auth','defaults','selector','routes','aliases','templates','dugdales'], 'root');
        return new Config(
            auth: self::buildAuth($data['auth'] ?? []),
            defaults: self::buildDefaults($data['defaults'] ?? []),
            selector: self::buildSelector($data['selector'] ?? []),
            routes: self::buildRoutes($data['routes'] ?? []),
            aliases: self::buildAliases($data['aliases'] ?? []),
            templates: self::buildTemplates($data['templates'] ?? []),
            dugdales: self::buildDugdales($data['dugdales'] ?? []),
        );
    }

    /** @param array<string, mixed> $d */
    private static function buildAuth(array $d): Auth
    {
        self::checkKeys($d, ['token','admin_token','exec_token'], 'auth');
        return new Auth(
            token: (string) ($d['token'] ?? ''),
            adminToken: (string) ($d['admin_token'] ?? ''),
            execToken: (string) ($d['exec_token'] ?? ''),
        );
    }

    /** @param array<string, mixed> $d */
    private static function buildDefaults(array $d): Defaults
    {
        self::checkKeys($d, ['port'], 'defaults');
        return new Defaults(port: (int) ($d['port'] ?? 0));
    }

    /** @param array<string, mixed> $d */
    private static function buildSelector(array $d): Selector
    {
        self::checkKeys($d, ['match'], 'selector');
        /** @var list<string> $m */
        $m = $d['match'] ?? [];
        return new Selector(match: $m);
    }

    /**
     * @param array<string, array<string, string>> $d
     * @return array<string, Route>
     */
    private static function buildRoutes(array $d): array
    {
        $out = [];
        foreach ($d as $name => $r) {
            if (!is_array($r) || !isset($r['host'], $r['lane'])) {
                throw new ConfigException("routes[$name]: must have host and lane");
            }
            $out[$name] = new Route(host: $r['host'], lane: $r['lane']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    private static function buildAliases(array $d): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, Template>
     */
    private static function buildTemplates(array $d): array
    {
        $out = [];
        foreach ($d as $name => $t) {
            if (!is_array($t)) {
                throw new ConfigException("templates[$name]: must be a mapping");
            }
            self::checkKeys($t, ['mission_dir','runtime','labels','token','admin_token','exec_token','lanes'], "templates[$name]");
            $out[(string) $name] = new Template(
                missionDir: (string) ($t['mission_dir'] ?? ''),
                runtime: self::buildRuntime($t['runtime'] ?? []),
                labels: $t['labels'] ?? [],
                token: (string) ($t['token'] ?? ''),
                adminToken: (string) ($t['admin_token'] ?? ''),
                execToken: (string) ($t['exec_token'] ?? ''),
                lanes: self::buildLanes($t['lanes'] ?? []),
            );
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $d
     * @return list<Dugdale>
     */
    private static function buildDugdales(array $d): array
    {
        $out = [];
        foreach ($d as $i => $entry) {
            if (!is_array($entry)) {
                throw new ConfigException("dugdales[$i]: must be a mapping");
            }
            self::checkKeys($entry, ['id','host','port','url','extends','mission_dir','runtime','labels','token','admin_token','exec_token','lanes'], "dugdales[$i]");
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                throw new ConfigException("dugdales[$i]: id is required");
            }
            // A null lane value means "delete the inherited template lane".
            // Separate those out before building LaneCfg DTOs so
            // they don't trip the "must have concurrency" check.
            $rawLanes = $entry['lanes'] ?? [];
            $nullifiedLanes = [];
            $laneEntries = [];
            if (is_array($rawLanes)) {
                foreach ($rawLanes as $name => $l) {
                    if ($l === null) {
                        $nullifiedLanes[] = (string) $name;
                    } else {
                        $laneEntries[(string) $name] = $l;
                    }
                }
            }
            $out[] = new Dugdale(
                id: $id,
                host: (string) ($entry['host'] ?? ''),
                port: (int) ($entry['port'] ?? 0),
                url: (string) ($entry['url'] ?? ''),
                extends: (string) ($entry['extends'] ?? ''),
                missionDir: (string) ($entry['mission_dir'] ?? ''),
                runtime: self::buildRuntime($entry['runtime'] ?? []),
                labels: $entry['labels'] ?? [],
                token: (string) ($entry['token'] ?? ''),
                adminToken: (string) ($entry['admin_token'] ?? ''),
                execToken: (string) ($entry['exec_token'] ?? ''),
                lanes: self::buildLanes($laneEntries),
                nullifiedLanes: $nullifiedLanes,
            );
        }
        return $out;
    }

    /** @param array<string, mixed> $d */
    private static function buildRuntime(array $d): Runtime
    {
        self::checkKeys($d, ['mission_path_template','command_template','validate_mission_file'], 'runtime');
        return new Runtime(
            missionPathTemplate: (string) ($d['mission_path_template'] ?? ''),
            commandTemplate: $d['command_template'] ?? [],
            validateMissionFile: (bool) ($d['validate_mission_file'] ?? false),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $d
     * @return array<string, LaneCfg>
     */
    private static function buildLanes(array $d): array
    {
        $out = [];
        foreach ($d as $name => $l) {
            if (!is_array($l) || !isset($l['concurrency'])) {
                throw new ConfigException("lane $name: must have concurrency");
            }
            self::checkKeys($l, ['concurrency','paused'], "lane $name");
            $out[(string) $name] = new LaneCfg(
                concurrency: (int) $l['concurrency'],
                paused: (bool) ($l['paused'] ?? false),
            );
        }
        return $out;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private static function checkKeys(array $data, array $allowed, string $where): void
    {
        foreach (array_keys($data) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                throw new ConfigException("$where: unknown key: $k");
            }
        }
    }
}
