<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\ControlChannel;
use PHPUnit\Framework\TestCase;

final class ControlChannelTest extends TestCase
{
    public function testEmitsJsonPerLine(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cc');
        $fh = fopen($tmp, 'wb');
        $cc = new ControlChannel($fh);
        $cc->emit(['event' => 'progress', 'value' => 0.5]);
        $cc->emit(['event' => 'progress', 'value' => 1.0]);
        fclose($fh);
        $contents = file_get_contents($tmp);
        $lines = explode("\n", rtrim($contents, "\n"));
        $this->assertCount(2, $lines);
        $this->assertSame(['event' => 'progress', 'value' => 0.5], json_decode($lines[0], true));
        $this->assertSame(['event' => 'progress', 'value' => 1.0], json_decode($lines[1], true));
        unlink($tmp);
    }
}
