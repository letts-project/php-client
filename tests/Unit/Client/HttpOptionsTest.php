<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;

/**
 * The documented client tuning knobs must reach the transport layer as the
 * corresponding HttpClient default options — not be silently dropped.
 */
final class HttpOptionsTest extends TestCase
{
    public function testRequestTimeoutMapsToIdleTimeout(): void
    {
        $opts = Client::httpDefaultOptions(['request_timeout' => 20]);
        $this->assertSame(20, $opts['timeout']);
    }

    public function testConnectTimeoutMapsToMaxConnectDuration(): void
    {
        $opts = Client::httpDefaultOptions(['connect_timeout' => 3]);
        $this->assertSame(3, $opts['max_connect_duration']);
    }

    public function testDefaultsAreSane(): void
    {
        $opts = Client::httpDefaultOptions([]);
        $this->assertSame(30, $opts['timeout']);
        $this->assertArrayNotHasKey('max_connect_duration', $opts);
    }
}
