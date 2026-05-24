<?php
declare(strict_types=1);

namespace Letts\Tests\Integration;

use Letts\Exceptions\StagingException;
use Letts\Tests\Integration\support\DugdaleFixture;

/**
 * Output-file download: artifacts can be far larger than the PHP memory
 * limit, so downloads must stream to disk, verify integrity, and report
 * write failures instead of silently succeeding.
 */
final class ClientBigOutputDownloadTest extends DugdaleFixture
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            @chmod($this->dir, 0o700);
            foreach (glob("$this->dir/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testLargeOutputStreamsToDiskWithBoundedMemory(): void
    {
        $this->dir = sys_get_temp_dir() . '/letts-dl-' . uniqid();
        $mib = 32;

        memory_reset_peak_usage();
        $base = memory_get_peak_usage(true);
        $r = $this->client()->run(
            host: 'local', lane: 'normal', mission: 'big_output',
            input: ['mib' => $mib],
            downloadOutputsTo: $this->dir,
        );
        $peakDelta = memory_get_peak_usage(true) - $base;

        $this->assertTrue($r->isSuccess());
        $path = "$this->dir/blob";
        $this->assertFileExists($path);
        $this->assertSame($mib * 1024 * 1024, filesize($path));
        // The mission reported the sha256 of what it wrote; the local copy
        // must match byte-for-byte.
        $this->assertSame($r->return['sha256'], hash_file('sha256', $path));
        $this->assertLessThan(
            16 * 1024 * 1024,
            $peakDelta,
            sprintf('a %dMiB download must stream to disk, not buffer in RAM (peak delta %.1fMiB)', $mib, $peakDelta / 1048576),
        );
    }

    public function testUnwritableDownloadDirFailsLoudly(): void
    {
        $this->dir = sys_get_temp_dir() . '/letts-ro-' . uniqid();
        mkdir($this->dir, 0o500, recursive: true);

        $this->expectException(StagingException::class);
        $this->client()->run(
            host: 'local', lane: 'normal', mission: 'output_file',
            input: [],
            downloadOutputsTo: $this->dir,
        );
    }
}
