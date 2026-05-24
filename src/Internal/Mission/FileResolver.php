<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Resolves mission file keys to filesystem paths under $workDir. Dugdale
 * materializes inputs to <workDir>/in/<key> before mission start; outputs
 * created by mission go to <workDir>/out/<key> and are picked up by dugdale
 * after success().
 */
final class FileResolver
{
    /** @param array<string, array{size: int, sha256: string}> $files */
    public function __construct(
        private readonly string $workDir,
        private readonly array $files,
    ) {}

    public function inputPath(string $key): string
    {
        if (!isset($this->files[$key])) {
            throw new \InvalidArgumentException("no input file with key \"$key\"");
        }
        return "$this->workDir/in/$key";
    }

    public function outputPath(string $key): string
    {
        return "$this->workDir/out/$key";
    }

    /** @return array{path: string, size: int, sha256: string} */
    public function fileInfo(string $key): array
    {
        if (!isset($this->files[$key])) {
            throw new \InvalidArgumentException("no input file with key \"$key\"");
        }
        return [
            'path' => $this->inputPath($key),
            'size' => $this->files[$key]['size'],
            'sha256' => $this->files[$key]['sha256'],
        ];
    }

    /** @return array<string, array{path: string, size: int, sha256: string}> */
    public function files(): array
    {
        $out = [];
        foreach ($this->files as $key => $meta) {
            $out[$key] = ['path' => "$this->workDir/in/$key", ...$meta];
        }
        return $out;
    }

    /**
     * Build the input-file map from the mission process environment. Dugdale
     * delivers per-file metadata via env vars, NOT inside the
     * stdin payload:
     *
     *   LETTS_IN_<role>           = <absolute materialized path>
     *   LETTS_IN_<role>__SHA256   = <hex>
     *   LETTS_IN_<role>__SIZE     = <bytes>
     *
     * The path var introduces the role; size/sha256 are looked up by exact
     * name so a role that itself ends in `_SHA256`/`_SIZE` is unambiguous
     * (the reserved double-underscore suffix only appears on metadata vars).
     *
     * @param array<string, string> $env  Typically `getenv()`.
     * @return array<string, array{size: int, sha256: string}>
     */
    public static function parseEnv(array $env): array
    {
        $out = [];
        foreach ($env as $name => $value) {
            if (!str_starts_with($name, 'LETTS_IN_')) {
                continue;
            }
            $role = substr($name, strlen('LETTS_IN_'));
            // Skip metadata vars — they are resolved via the role's path var.
            if (str_ends_with($role, '__SHA256') || str_ends_with($role, '__SIZE')) {
                continue;
            }
            $out[$role] = [
                'size'   => (int) ($env["LETTS_IN_{$role}__SIZE"] ?? 0),
                'sha256' => (string) ($env["LETTS_IN_{$role}__SHA256"] ?? ''),
            ];
        }
        return $out;
    }
}
