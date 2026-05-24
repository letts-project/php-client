<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Tracks output keys registered by mission via $m->outputFile(). Dugdale
 * collects and hashes the files itself once `success` is reported on fd 3
 * (see internal/mission/output_collect.go CollectOutputs), so the registry
 * does NOT compute hashes — it just confirms that every registered key has a
 * regular file at <workDir>/out/<key> before we report success, giving the
 * mission author a clear error rather than an opaque `missing_output` from
 * dugdale.
 */
final class OutputRegistry
{
    /** @var list<string> */
    private array $keys = [];

    public function __construct(private readonly string $workDir) {}

    public function register(string $key): void
    {
        if (!in_array($key, $this->keys, true)) {
            $this->keys[] = $key;
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * Verify every registered key resolves to a regular file under
     * <workDir>/out/<key>. Throws on the first missing key. Called from
     * Mission::success() before emitting the fd 3 success event.
     */
    public function assertAllPresent(): void
    {
        foreach ($this->keys as $key) {
            $path = "$this->workDir/out/$key";
            if (!is_file($path)) {
                throw new \RuntimeException("outputFile registered but missing: $key (expected at $path)");
            }
        }
    }
}
