<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

/**
 * Fan-out waiting must respect waitTimeout: a stuck/slow mission may not pin
 * the caller forever, and the affected host must surface a timeout error.
 */
final class ClientParallelWaitTimeoutTest extends DugdaleFixture
{
    public function testRunOnAllStopsWaitingAtWaitTimeout(): void
    {
        $t0 = microtime(true);
        $results = $this->client()->runOnAll(
            mission: 'quiet_sleep', lane: 'normal', match: ['test'],
            input: ['seconds' => 5],
            waitTimeout: '1s',
        );
        $elapsed = microtime(true) - $t0;

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->error, 'a mission still running at the deadline is an error, not a result');
        $this->assertSame('timeout', $results[0]->error->kind);
        $this->assertLessThan(4.0, $elapsed, "runOnAll must return at waitTimeout (took {$elapsed}s)");
    }
}
