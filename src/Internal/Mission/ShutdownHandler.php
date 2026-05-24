<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Best-effort PHP fatal/OOM detector for missions. Allocates a 64 KB reserve
 * buffer on install so that shutdown_function can fire even when the script
 * died of OOM (memory_limit reached). Releases the reserve in install(), then
 * register_shutdown_function() inspects error_get_last() to classify the
 * cause: E_ERROR with 'memory' substring → outcome=oom, other fatal levels
 * → outcome=crashed.
 *
 * Caller is responsible for actually wiring report()'s return into the fd-3
 * control channel as a `done` event — Mission::start() does that.
 */
final class ShutdownHandler
{
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    private const RESERVE_BYTES = 65536;

    private string $reserve = '';

    /**
     * @param \Closure(array{outcome:string,fail_message:string,fail_details:array<string,mixed>}):void|null $sink
     */
    public static function install(?\Closure $sink = null): self
    {
        $h = new self();
        $h->reserve = str_repeat('x', self::RESERVE_BYTES);
        register_shutdown_function(function () use ($h, $sink) {
            $h->reserve = '';
            $r = $h->report(error_get_last());
            if ($r !== null && $sink !== null) {
                $sink($r);
            }
        });
        return $h;
    }

    public function reserveBytes(): int
    {
        return strlen($this->reserve);
    }

    /**
     * @param array{type:int,message:string,file:string,line:int}|null $err
     * @return array{outcome:string,fail_message:string,fail_details:array<string,mixed>}|null
     */
    public function report(?array $err): ?array
    {
        if ($err === null || !in_array($err['type'], self::FATAL_TYPES, true)) {
            return null;
        }
        $isOom = str_contains(strtolower($err['message']), 'memory');
        return [
            'outcome' => $isOom ? 'oom' : 'crashed',
            'fail_message' => $err['message'],
            'fail_details' => [
                'file' => $err['file'],
                'line' => $err['line'],
                'type' => $err['type'],
            ],
        ];
    }
}
