<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal;

use Letts\Internal\IdsUuidV7;
use PHPUnit\Framework\TestCase;

final class IdsUuidV7Test extends TestCase
{
    public function testFormatIsValidUuid(): void
    {
        $id = IdsUuidV7::generate();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testTimestampOrderingMonotonic(): void
    {
        $a = IdsUuidV7::generate();
        usleep(2000);
        $b = IdsUuidV7::generate();
        $this->assertLessThan($b, $a, 'UUIDv7 must sort lexicographically by time');
    }

    public function testUniqueness(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = IdsUuidV7::generate();
        }
        $this->assertCount(1000, array_unique($ids));
    }
}
