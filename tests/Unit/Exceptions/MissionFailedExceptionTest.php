<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Exceptions;

use Letts\Exceptions\MissionFailedException;
use PHPUnit\Framework\TestCase;

final class MissionFailedExceptionTest extends TestCase
{
    public function testGettersExposeAllFields(): void
    {
        $e = new MissionFailedException(
            outcome: 'failed',
            reason: 'explicit',
            failMessage: 'record not found',
            failDetails: ['file' => 'X.php', 'line' => 42],
            result: null,
        );
        $this->assertSame('failed', $e->getOutcome());
        $this->assertSame('explicit', $e->getReason());
        $this->assertSame('record not found', $e->getFailMessage());
        $this->assertSame(['file' => 'X.php', 'line' => 42], $e->getFailDetails());
        $this->assertNull($e->getResult());
    }

    public function testMessageBuiltFromFailMessage(): void
    {
        $e = new MissionFailedException('failed', 'explicit', 'boom', null, null);
        $this->assertSame('mission failed: boom', $e->getMessage());
    }
}
