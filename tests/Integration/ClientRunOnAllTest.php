<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\TwoDugdaleFixture;

final class ClientRunOnAllTest extends TwoDugdaleFixture
{
    public function testFanOutsOnLabelMatch(): void
    {
        $results = $this->client()->runOnAll(
            mission: 'echo_input',
            lane: 'normal',
            match: ['prod'],
            input: ['ping' => true],
        );
        $this->assertCount(2, $results);
        foreach ($results as $r) {
            $this->assertTrue($r->isReachable());
            $this->assertTrue(
                $r->isSuccess(),
                "host {$r->host} failed: " . ($r->error?->message ?? ''),
            );
            $this->assertSame(['ping' => true], $r->result->return);
        }
    }

    public function testRunParallelRunsConcurrently(): void
    {
        // Two missions that each sleep ~1s on two separate dugdales. Sequential
        // following would take ~2s+; multiplexed (curl_multi) following is
        // bounded by the slowest single job (~1s + overhead). Generous ceiling
        // keeps this non-flaky while still proving concurrency.
        $start = microtime(true);
        $results = $this->client()->runParallel([
            ['host' => 's1', 'lane' => 'normal', 'mission' => 'sleep_then_succeed'],
            ['host' => 's2', 'lane' => 'normal', 'mission' => 'sleep_then_succeed'],
        ]);
        $elapsed = microtime(true) - $start;

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccess(), $results[0]->error?->message ?? '');
        $this->assertTrue($results[1]->isSuccess(), $results[1]->error?->message ?? '');
        $this->assertLessThan(1.8, $elapsed, "runParallel was not concurrent: {$elapsed}s for 2×1s jobs");
    }
}
