<?php
declare(strict_types=1);

namespace Letts\Tests\Unit\Client;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class DugdalesTest extends TestCase
{
    public function testReturnsAllDugdalesWhenMatchEmpty(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dg');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            dugdales:
              - id: s1
                host: h
                token: t
              - id: s2
                host: h
                token: t
            YAML);
        $client = Client::fromConfig($tmp, http: new MockHttpClient());
        unlink($tmp);
        $ids = array_map(fn($d) => $d->id, $client->dugdales());
        $this->assertSame(['s1', 's2'], $ids);
    }

    public function testFiltersByLabels(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dg');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            dugdales:
              - id: s1
                host: h
                token: t
                labels: [prod]
              - id: s2
                host: h
                token: t
                labels: [dev]
            YAML);
        $client = Client::fromConfig($tmp, http: new MockHttpClient());
        unlink($tmp);
        $ids = array_map(fn($d) => $d->id, $client->dugdales(['prod']));
        $this->assertSame(['s1'], $ids);
    }
}
