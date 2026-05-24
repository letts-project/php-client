<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Config;

use Letts\Config\Auth;
use Letts\Config\Defaults;
use Letts\Config\LaneCfg;
use Letts\Config\Route;
use Letts\Config\Selector;
use PHPUnit\Framework\TestCase;

final class SimpleDtoTest extends TestCase
{
    public function testAuthHoldsAllThreeTokens(): void
    {
        $a = new Auth(token: 'a', adminToken: 'b', execToken: 'c');
        $this->assertSame('a', $a->token);
        $this->assertSame('b', $a->adminToken);
        $this->assertSame('c', $a->execToken);
    }

    public function testAuthDefaultsToNullStrings(): void
    {
        $a = new Auth();
        $this->assertSame('', $a->token);
        $this->assertSame('', $a->adminToken);
        $this->assertSame('', $a->execToken);
    }

    public function testDefaultsHoldsPort(): void
    {
        $d = new Defaults(port: 7180);
        $this->assertSame(7180, $d->port);
    }

    public function testSelectorHoldsMatchList(): void
    {
        $s = new Selector(match: ['prod', 'backend']);
        $this->assertSame(['prod', 'backend'], $s->match);
    }

    public function testRouteHoldsHostAndLane(): void
    {
        $r = new Route(host: 'local', lane: 'normal');
        $this->assertSame('local', $r->host);
        $this->assertSame('normal', $r->lane);
    }

    public function testLaneCfgHoldsConcurrencyAndPaused(): void
    {
        $l = new LaneCfg(concurrency: 4, paused: true);
        $this->assertSame(4, $l->concurrency);
        $this->assertTrue($l->paused);
    }

    public function testLaneCfgPausedDefaultsFalse(): void
    {
        $l = new LaneCfg(concurrency: 1);
        $this->assertFalse($l->paused);
    }
}
