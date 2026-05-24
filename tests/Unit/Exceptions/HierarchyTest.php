<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Exceptions;

use Letts\Exceptions\AuthException;
use Letts\Exceptions\BackpressureException;
use Letts\Exceptions\BadRequestException;
use Letts\Exceptions\ConfigException;
use Letts\Exceptions\ConflictException;
use Letts\Exceptions\DispatchException;
use Letts\Exceptions\InterruptedException;
use Letts\Exceptions\LettsException;
use Letts\Exceptions\StagingException;
use PHPUnit\Framework\TestCase;

final class HierarchyTest extends TestCase
{
    public function testAllExtendLettsException(): void
    {
        $classes = [
            ConfigException::class, AuthException::class,
            BadRequestException::class, ConflictException::class,
            BackpressureException::class, DispatchException::class,
            StagingException::class, InterruptedException::class,
        ];
        foreach ($classes as $cls) {
            $this->assertTrue(
                is_subclass_of($cls, LettsException::class),
                "$cls must extend LettsException",
            );
        }
    }

    public function testLettsExceptionIsAbstract(): void
    {
        $r = new \ReflectionClass(LettsException::class);
        $this->assertTrue($r->isAbstract());
    }

    public function testInstantiateAndCatch(): void
    {
        try {
            throw new BadRequestException('bad');
        } catch (LettsException $e) {
            $this->assertSame('bad', $e->getMessage());
        }
    }
}
