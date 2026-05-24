<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Exceptions;

use Letts\Exceptions\LettsException;
use Letts\Exceptions\NetworkException;
use PHPUnit\Framework\TestCase;

final class NetworkExceptionTest extends TestCase
{
    public function testCarriesHostAndMessage(): void
    {
        $e = new NetworkException('s1', 'connection refused');
        $this->assertSame('s1', $e->getHost());
        $this->assertStringContainsString('connection refused', $e->getMessage());
    }

    public function testIsLettsException(): void
    {
        $this->assertInstanceOf(LettsException::class, new NetworkException('s1', 'x'));
    }

    public function testPreservesCause(): void
    {
        $cause = new \RuntimeException('underlying');
        $e = new NetworkException('s1', 'wrap', $cause);
        $this->assertSame($cause, $e->getPrevious());
    }
}
