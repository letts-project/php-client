<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class ClientKillRestartDeleteTest extends DugdaleFixture
{
    public function testKillRestartDelete(): void
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
        $this->assertSame('done', $info?->status);
        $this->assertContains($info->outcome, ['killed', 'failed']);

        $newId = $c->restart($id, host: 'local');
        $this->assertNotEmpty($newId);
        $this->assertNotSame($id, $newId);

        $c->delete($id, host: 'local', force: true);
        // The daemon answers 404 not_found while the row sits in
        // status='deleting'; Client::getMission catches the 404 and returns
        // null. The row is physically purged by the cleanup sweeper later,
        // but that's not observable via the API.
        $afterDelete = $c->getMission($id, host: 'local');
        $this->assertNull($afterDelete);
    }
}
