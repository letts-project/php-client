<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Wraps the mission input JSON with dot-notation accessors. Mission users see
 * this via $m->input(), $m->all(), $m->has(). Sentinel default object used to
 * distinguish "no default supplied" from "default is null".
 */
final class InputParser
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromString(string $json): self
    {
        if ($json === '') {
            return new self([]);
        }
        // The mission stdin is the raw user-input JSON, which may
        // be an object, or the literal `null` when input was empty. A top-level
        // scalar or null cannot be dot-navigated, so we degrade to an empty
        // input set rather than fataling the mission on startup.
        $d = json_decode($json, true);
        return new self(is_array($d) ? $d : []);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $hasDefault = func_num_args() > 1;
        $cur = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                if ($hasDefault) {
                    return $default;
                }
                throw new \InvalidArgumentException("input path \"$path\" not found");
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    public function has(string $path): bool
    {
        $cur = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return false;
            }
            $cur = $cur[$key];
        }
        return true;
    }
}
