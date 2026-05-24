<?php
// tests/Unit/AutoloadSmokeTest.php
declare(strict_types=1);

namespace Letts\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AutoloadSmokeTest extends TestCase
{
    public function testSymfonyHttpClientLoads(): void
    {
        $this->assertTrue(class_exists(\Symfony\Component\HttpClient\HttpClient::class));
    }

    public function testSymfonyYamlLoads(): void
    {
        $this->assertTrue(class_exists(\Symfony\Component\Yaml\Yaml::class));
    }
}
