<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Dugdale;
use Letts\Config\LaneCfg;
use Letts\Config\Runtime;
use Letts\Config\Template;
use PHPUnit\Framework\TestCase;

final class DugdaleTemplateTest extends TestCase
{
    public function testRuntimeHoldsMissionPathAndCommandTemplate(): void
    {
        $r = new Runtime(
            missionPathTemplate: '{mission}.php',
            commandTemplate: ['php', '{mission_path}'],
            validateMissionFile: true,
        );
        $this->assertSame('{mission}.php', $r->missionPathTemplate);
        $this->assertSame(['php', '{mission_path}'], $r->commandTemplate);
        $this->assertTrue($r->validateMissionFile);
    }

    public function testTemplateHoldsAllFields(): void
    {
        $t = new Template(
            missionDir: '/var/www/missions',
            runtime: new Runtime(),
            labels: ['prod', 'backend'],
            token: 't',
            adminToken: 'at',
            execToken: 'et',
            lanes: ['normal' => new LaneCfg(concurrency: 4)],
        );
        $this->assertSame('/var/www/missions', $t->missionDir);
        $this->assertSame(['prod', 'backend'], $t->labels);
        $this->assertSame(4, $t->lanes['normal']->concurrency);
    }

    public function testDugdaleHasLaneAndHasLabelHelpers(): void
    {
        $d = new Dugdale(
            id: 's1',
            host: 'server1.internal',
            labels: ['prod'],
            lanes: ['normal' => new LaneCfg(concurrency: 1)],
        );
        $this->assertTrue($d->hasLane('normal'));
        $this->assertFalse($d->hasLane('missing'));
        $this->assertTrue($d->hasLabel('prod'));
        $this->assertFalse($d->hasLabel('dev'));
    }
}
