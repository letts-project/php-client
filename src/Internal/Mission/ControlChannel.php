<?php
declare(strict_types=1);

namespace Letts\Internal\Mission;

/**
 * Writer for mission → dugdale control events on fd 3. Emits one JSON object
 * per line. fopen('php://fd/3', 'wb') is the production opener; tests inject
 * any writable resource.
 *
 * Tracks whether a terminal `success`/`fail` event has already been emitted
 * so that the shutdown handler can skip its own emit and avoid tripping
 * dugdale's duplicate_final_event violation.
 */
final class ControlChannel
{
    private bool $finalEmitted = false;

    /** @param resource $fd */
    public function __construct(private $fd) {}

    public static function openFd3(): self
    {
        $fd = @fopen('php://fd/3', 'wb');
        if (!is_resource($fd)) {
            throw new \RuntimeException('fd 3 not writable (mission misconfiguration)');
        }
        return new self($fd);
    }

    /** @param array<string, mixed> $event */
    public function emit(array $event): void
    {
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        fwrite($this->fd, $line);
        fflush($this->fd);
        $kind = $event['event'] ?? null;
        if ($kind === 'success' || $kind === 'fail') {
            $this->finalEmitted = true;
        }
    }

    public function finalEmitted(): bool
    {
        return $this->finalEmitted;
    }

    public function close(): void
    {
        if (is_resource($this->fd)) {
            fclose($this->fd);
        }
    }
}
