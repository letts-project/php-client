<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class MissionSignalInterruptTest extends DugdaleFixture
{
    public function testKillTriggersInterruptedException(): void
    {
        $c = $this->client();
        $id = $c->dispatch(host: 'local', lane: 'normal', mission: 'sleep_interruptible');
        usleep(300_000);

        $c->kill($id, signal: 'TERM', host: 'local');

        $deadline = microtime(true) + 5;
        $info = null;
        while (microtime(true) < $deadline) {
            $info = $c->getMission($id, host: 'local');
            if ($info?->status === 'done') break;
            usleep(100_000);
        }
        $this->assertNotNull($info);
        $this->assertSame('done', $info->status);
        $this->assertContains($info->outcome, ['killed', 'failed']);
    }
}
