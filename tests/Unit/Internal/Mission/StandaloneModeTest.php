<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Internal\Mission;

use Letts\Internal\Mission\StandaloneMode;
use PHPUnit\Framework\TestCase;

final class StandaloneModeTest extends TestCase
{
    public function testParsesInputLiteralFromArgv(): void
    {
        $argv = ['script.php', '--input={"item_id":42}'];
        $input = StandaloneMode::loadInput($argv);
        $this->assertSame(['item_id' => 42], $input);
    }

    public function testParsesInputFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sm');
        file_put_contents($tmp, '{"x":1}');
        $argv = ['script.php', "--input-file=$tmp"];
        $this->assertSame(['x' => 1], StandaloneMode::loadInput($argv));
        unlink($tmp);
    }

    public function testEmptyArgvProducesEmptyInput(): void
    {
        $this->assertSame([], StandaloneMode::loadInput(['script.php']));
    }

    public function testProgressFormattedForStderr(): void
    {
        $line = StandaloneMode::formatProgress(0.5, 'halfway');
        $this->assertSame("[progress] 0.50 halfway\n", $line);
    }

    public function testProgressMessageOnly(): void
    {
        $line = StandaloneMode::formatProgress(null, 'starting');
        $this->assertSame("[progress] starting\n", $line);
    }
}
