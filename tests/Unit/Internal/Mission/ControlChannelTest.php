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

    public function testEmitsUnicodeAndSlashesUnescaped(): void
    {
        $event = [
            'event' => 'success',
            'return' => ['path' => '/книги/Автор/01 Глава.mp3', 'size' => 1.0],
        ];
        $line = $this->emitAndRead(fn(ControlChannel $cc) => $cc->emit($event));
        $this->assertStringContainsString('/книги/Автор/01 Глава.mp3', $line);
        $this->assertStringNotContainsString('\\u', $line);
        $this->assertStringNotContainsString('\\/', $line);
        $this->assertStringContainsString('"size":1.0', $line);
        $this->assertSame($event, json_decode($line, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFailEventWithInvalidUtf8IsEmitted(): void
    {
        $line = $this->emitAndRead(function (ControlChannel $cc): void {
            $cc->emit([
                'event' => 'fail',
                'message' => "cannot open \xcf\xf0\xe8.mp3",
                'details' => ['file' => "/tmp/\xcf\xf0\xe8.mp3"],
            ]);
            $this->assertTrue($cc->finalEmitted());
        });
        $this->assertSame([
            'event' => 'fail',
            'message' => "cannot open \u{FFFD}\u{FFFD}\u{FFFD}.mp3",
            'details' => ['file' => "/tmp/\u{FFFD}\u{FFFD}\u{FFFD}.mp3"],
        ], json_decode($line, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSuccessEventWithInvalidUtf8Throws(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cc');
        $fh = fopen($tmp, 'wb');
        $cc = new ControlChannel($fh);
        try {
            $this->expectException(\JsonException::class);
            $cc->emit(['event' => 'success', 'return' => "\xcf\xf0\xe8"]);
        } finally {
            fclose($fh);
            $this->assertSame('', file_get_contents($tmp));
            $this->assertFalse($cc->finalEmitted());
            unlink($tmp);
        }
    }

    /** @param callable(ControlChannel): void $fn */
    private function emitAndRead(callable $fn): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cc');
        $fh = fopen($tmp, 'wb');
        $fn(new ControlChannel($fh));
        fclose($fh);
        $contents = file_get_contents($tmp);
        unlink($tmp);
        $this->assertStringEndsWith("\n", $contents);
        $this->assertSame(1, substr_count($contents, "\n"));
        return rtrim($contents, "\n");
    }
}
