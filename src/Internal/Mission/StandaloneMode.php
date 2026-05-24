<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Standalone debug runner used when LETTS_MISSION_ID env is unset. Parses
 * --input=/--input-file= from argv (no real CLI lib — small surface). The
 * Mission class delegates here when bootstrapping in standalone mode.
 */
final class StandaloneMode
{
    /**
     * @param list<string> $argv
     * @return array<string, mixed>
     */
    public static function loadInput(array $argv): array
    {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--input-file=')) {
                $path = substr($arg, 13);
                if ($path === '-') {
                    return self::decode((string) file_get_contents('php://stdin'));
                }
                $body = file_get_contents($path);
                if ($body === false) {
                    throw new \RuntimeException("cannot read input file: $path");
                }
                return self::decode($body);
            }
            if (str_starts_with($arg, '--input=')) {
                $val = substr($arg, 8);
                if ($val === '-') {
                    return self::decode((string) file_get_contents('php://stdin'));
                }
                return self::decode($val);
            }
        }
        return [];
    }

    public static function formatProgress(?float $value, ?string $message): string
    {
        if ($value !== null) {
            return sprintf("[progress] %.2f %s\n", $value, $message ?? '');
        }
        return "[progress] " . ($message ?? '') . "\n";
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            throw new \InvalidArgumentException('input is not a JSON object');
        }
        return $d;
    }
}
