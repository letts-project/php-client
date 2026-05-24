<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientEventReconnectTest extends DugdaleFixture
{
    public function testLongRunningStreamReachesTerminal(): void
    {
        $events = 0;
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'progress_steps',
            onProgress: function () use (&$events) { $events++; },
        );
        $this->assertTrue($r->isSuccess());
        $this->assertGreaterThanOrEqual(5, $events);
    }
}
