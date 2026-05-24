<?php
declare(strict_types=1);

namespace Letts\Internal;

/**
 * Generates RFC 9562 UUIDv7 (time-ordered, 48-bit ms timestamp + 74-bit
 * randomness). Mirrors Go internal/ids contract.
 */
final class IdsUuidV7
{
    public static function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $rand = random_bytes(10);

        $bytes = pack('J', $ms);
        $bytes = substr($bytes, 2);
        $bytes .= $rand;

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
