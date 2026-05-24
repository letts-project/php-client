<?php
declare(strict_types=1);

namespace Letts\Tests\Unit;

use Letts\Client;
use Letts\Config\Auth;
use Letts\Config\Config;
use Letts\Config\Dugdale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class ClientConstructionTest extends TestCase
{
    public function testFromConfigInjectsMockTransport(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'letts-c');
        chmod($tmp, 0600);
        file_put_contents($tmp, <<<YAML
            auth:
              token: "\${TOK}"
            dugdales:
              - id: s1
                host: localhost
                port: 7180
            YAML);
        putenv('TOK=resolved');
        $client = Client::fromConfig($tmp, http: new MockHttpClient());
        $this->assertInstanceOf(Client::class, $client);
        putenv('TOK');
        unlink($tmp);
    }

    public function testFromConfigAcceptsTimeoutOpts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'letts-c');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n");
        $client = Client::fromConfig($tmp, [
            'connect_timeout' => 5,
            'request_timeout' => 30,
            'retry_attempts'  => 5,
            'retry_backoff'   => [50, 200, 1000],
        ], http: new MockHttpClient());
        $this->assertInstanceOf(Client::class, $client);
        unlink($tmp);
    }

    /**
     * Regression: fromConfig() must build the default HttpClient without
     * injecting a mock. max_connections_per_host has to be passed as the
     * second positional arg to HttpClient::create(), not as a per-request
     * default option — otherwise CurlHttpClient rejects "max_host_connections".
     */
    public function testFromConfigBuildsRealHttpClientWithConnectionPoolOpt(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'letts-c');
        chmod($tmp, 0600);
        file_put_contents($tmp, "dugdales:\n  - id: s1\n    host: h\n    token: t\n");
        // No `http:` arg → exercises the HttpClient::create(...) branch.
        $client = Client::fromConfig($tmp, [
            'request_timeout'          => 30,
            'max_connections_per_host' => 4,
        ]);
        $this->assertInstanceOf(Client::class, $client);
        unlink($tmp);
    }
}
