<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Config;
use Letts\Config\Dugdale;
use Letts\Config\HostSelector;
use Letts\Config\LaneCfg;
use Letts\Exceptions\NoMatchingDugdaleException;
use PHPUnit\Framework\TestCase;

final class HostSelectorTest extends TestCase
{
    public function testReturnsAllWhenMatchEmpty(): void
    {
        $c = new Config(dugdales: [
            new Dugdale(id: 's1'), new Dugdale(id: 's2'),
        ]);
        $out = HostSelector::candidates($c, []);
        $this->assertCount(2, $out);
    }

    public function testFiltersByLabelsAnd(): void
    {
        $c = new Config(dugdales: [
            new Dugdale(id: 's1', labels: ['prod', 'fast']),
            new Dugdale(id: 's2', labels: ['prod']),
            new Dugdale(id: 's3', labels: ['dev', 'fast']),
        ]);
        $out = HostSelector::candidates($c, ['prod', 'fast']);
        $this->assertCount(1, $out);
        $this->assertSame('s1', $out[0]->id);
    }

    public function testPickOneReturnsACandidate(): void
    {
        // Go picks randomly among >1 candidates (load distribution), not always
        // the first. We only assert the pick is a valid candidate.
        $c = new Config(dugdales: [
            new Dugdale(id: 's1', labels: ['prod']),
            new Dugdale(id: 's2', labels: ['prod']),
        ]);
        $this->assertContains(HostSelector::pickOne($c, ['prod'])->id, ['s1', 's2']);
    }

    public function testCandidatesFilterByLane(): void
    {
        // Auto-select / runOnAll must only consider dugdales that actually have
        // the requested lane (Go select.go: HasLane && hasAllLabels).
        $c = new Config(dugdales: [
            new Dugdale(id: 's1', labels: ['prod'], lanes: ['normal' => new LaneCfg(1)]),
            new Dugdale(id: 's2', labels: ['prod'], lanes: ['other'  => new LaneCfg(1)]),
        ]);
        $out = HostSelector::candidates($c, ['prod'], 'normal');
        $this->assertCount(1, $out);
        $this->assertSame('s1', $out[0]->id);
    }

    public function testPickOneRespectsLaneFilter(): void
    {
        $c = new Config(dugdales: [
            new Dugdale(id: 's1', labels: ['prod'], lanes: ['normal' => new LaneCfg(1)]),
            new Dugdale(id: 's2', labels: ['prod'], lanes: ['other'  => new LaneCfg(1)]),
        ]);
        $this->assertSame('s1', HostSelector::pickOne($c, ['prod'], 'normal')->id);
    }

    public function testPickOneThrowsOnEmptyResult(): void
    {
        $c = new Config(dugdales: [new Dugdale(id: 's1', labels: ['prod'])]);
        $this->expectException(NoMatchingDugdaleException::class);
        HostSelector::pickOne($c, ['nonexistent']);
    }
}
