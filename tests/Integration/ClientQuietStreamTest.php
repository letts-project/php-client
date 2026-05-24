<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Exceptions\WaitTimeoutException;
use Letts\Tests\Integration\support\DugdaleFixture;

/**
 * Missions that stay silent between `running` and `done` for longer than the
 * HTTP client's inactivity timeout. The event stream must ride through those
 * idle gaps without leaking PHP warnings (apps that convert warnings to
 * exceptions would otherwise lose a mission that is still running fine).
 */
final class ClientQuietStreamTest extends DugdaleFixture
{
    public function testQuietMissionOutlivesIdleTimeoutWithoutWarnings(): void
    {
        $warnings = [];
        set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            // request_timeout 2s vs 5s of stream silence: several idle
            // timeouts fire while the mission sleeps.
            $r = $this->client(['request_timeout' => 2])->run(
                host: 'local', lane: 'normal', mission: 'quiet_sleep',
                input: ['seconds' => 5],
            );
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($r->isSuccess());
        $this->assertSame([], $warnings, 'idle stream gaps must not leak PHP warnings');
    }

    public function testWaitTimeoutThrowsDedicatedExceptionPromptly(): void
    {
        $t0 = microtime(true);
        try {
            $this->client(['request_timeout' => 2])->run(
                host: 'local', lane: 'normal', mission: 'quiet_sleep',
                input: ['seconds' => 6],
                waitTimeout: '1s',
            );
            $this->fail('expected WaitTimeoutException');
        } catch (WaitTimeoutException) {
            $elapsed = microtime(true) - $t0;
            $this->assertLessThan(4.0, $elapsed, "waitTimeout=1s must not wait {$elapsed}s");
        }
    }
}
