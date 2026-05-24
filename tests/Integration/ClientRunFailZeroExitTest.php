<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Exceptions\MissionFailedException;
use Letts\Tests\Integration\support\DugdaleFixture;

/**
 * fail() with a zero exit code is a contradiction (a failure that tells the OS
 * "success"). The library must coerce the process to a non-zero exit so the
 * daemon attributes a normal explicit failure rather than the diagnostic
 * `fail_then_zero_exit` reason.
 */
final class ClientRunFailZeroExitTest extends DugdaleFixture
{
    public function testFailWithZeroExitIsRecordedAsExplicitFailure(): void
    {
        try {
            $this->client()->run(host: 'local', lane: 'normal', mission: 'fail_zero_exit');
            $this->fail('expected MissionFailedException');
        } catch (MissionFailedException $e) {
            $this->assertSame('failed', $e->getOutcome());
            $this->assertSame('explicit', $e->getReason());
            $this->assertSame('deliberate failure', $e->getFailMessage());
        }
    }
}
