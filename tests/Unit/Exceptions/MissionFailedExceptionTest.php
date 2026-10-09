<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Exceptions;

use Letts\Exceptions\MissionFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string, ?string, ?string, string}> */
    public static function messageCases(): iterable
    {
        yield 'daemon-classified reason' => [
            'failed', 'event_line_too_large', 'fd3 success event line exceeds 1048576 bytes',
            'mission failed: event_line_too_large: fd3 success event line exceeds 1048576 bytes',
        ];
        yield 'non-failed outcome without reason' => [
            'timeout', null, 'mission exceeded its timeout of 30s and was killed',
            'mission failed: timeout: mission exceeded its timeout of 30s and was killed',
        ];
        yield 'non-failed outcome with reason, no message' => [
            'killed', 'killed_by_api', null,
            'mission failed: killed/killed_by_api',
        ];
        yield 'non-failed outcome with empty reason' => [
            'timeout', '', '',
            'mission failed: timeout',
        ];
        yield 'nothing known' => ['failed', null, null, 'mission failed'];
        yield 'failed with empty reason and message' => ['failed', '', '', 'mission failed'];
        yield 'reason without message' => [
            'failed', 'nonzero_exit', null,
            'mission failed: nonzero_exit',
        ];
    }

    #[DataProvider('messageCases')]
    public function testMessageIncludesOutcomeReasonAndFailMessage(
        string $outcome,
        ?string $reason,
        ?string $failMessage,
        string $expected,
    ): void {
        $e = new MissionFailedException($outcome, $reason, $failMessage, null, null);
        $this->assertSame($expected, $e->getMessage());
    }
}
