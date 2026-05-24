<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Tests\Integration\support\DugdaleFixture;

final class MissionFd3Test extends DugdaleFixture
{
    public function testProgressEventsArriveViaFd3(): void
    {
        $captured = [];
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'progress_steps',
            onProgress: function (?float $v, ?string $m) use (&$captured) {
                $captured[] = ['v' => $v, 'm' => $m];
            },
        );
        $this->assertTrue($r->isSuccess());
        $this->assertCount(5, $captured);
        foreach ($captured as $i => $ev) {
            $this->assertSame("step " . ($i + 1), $ev['m']);
            // Cast expected to float: PHP collapses 5/5 to int(1), but the
            // value comes back from the wire as float(1.0). assertSame is
            // strict on type so we force float to match.
            $this->assertSame((float) (($i + 1) / 5), $ev['v']);
        }
    }
}
