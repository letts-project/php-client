<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Result;

use Letts\Result\HostError;
use PHPUnit\Framework\TestCase;

final class HostErrorTest extends TestCase
{
    public function testHoldsAllFields(): void
    {
        $e = new HostError('network', 'connection refused', null, null);
        $this->assertSame('network', $e->kind);
        $this->assertSame('connection refused', $e->message);
        $this->assertNull($e->httpStatus);
    }

    public function testWithHttpStatus(): void
    {
        $e = new HostError('bad_request', 'invalid input', 400, 'invalid_lane');
        $this->assertSame(400, $e->httpStatus);
        $this->assertSame('invalid_lane', $e->errorCode);
    }
}
