<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Exceptions\MissionFailedException;
use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientRunFailedTest extends DugdaleFixture
{
    public function testThrowsMissionFailedExceptionByDefault(): void
    {
        $this->expectException(MissionFailedException::class);
        try {
            $this->client()->run(host: 'local', lane: 'normal', mission: 'fail_message');
        } catch (MissionFailedException $e) {
            $this->assertSame('failed', $e->getOutcome());
            $this->assertSame('boom', $e->getFailMessage());
            $this->assertNotNull($e->getResult());
            throw $e;
        }
    }

    public function testThrowOnFailureFalseReturnsResult(): void
    {
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'fail_message',
            throwOnFailure: false,
        );
        $this->assertFalse($r->isSuccess());
        $this->assertSame('failed', $r->outcome);
        $this->assertSame('boom', $r->failMessage);
    }

    public function testUncaughtExceptionReportedAsUncaughtException(): void
    {
        // set_exception_handler emits fail(reason=uncaught_exception) with a
        // trace, distinct from $m->fail()'s reason=explicit.
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'throws_exception',
            throwOnFailure: false,
        );
        $this->assertSame('failed', $r->outcome);
        $this->assertSame('uncaught_exception', $r->failReason);
        $this->assertSame('kaboom', $r->failMessage);
        $this->assertIsArray($r->failDetails);
        $this->assertArrayHasKey('trace', $r->failDetails);
    }
}
