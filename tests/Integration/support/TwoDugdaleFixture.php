<?php
declare(strict_types=1);

namespace Letts\Tests\Integration\support;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Extends DugdaleFixture by spawning a SECOND dugdale on a different port,
 * exposing both in the generated letts.yaml as separate dugdale entries
 * with shared labels so runOnAll can fan out.
 *
 * NOTE: setUp re-writes letts.yaml to use IDs `s1` (parent's local) and
 * `s2` (second). Parent fixture's `local` dugdale ID gets overridden —
 * that's intentional.
 */
abstract class TwoDugdaleFixture extends DugdaleFixture
{
    protected int $port2;
    protected string $dataDir2;
    /** @var resource|null */
    private $proc2 = null;
    /** @var array<int, resource> */
    private array $pipes2 = [];

    protected function setUp(): void
    {
        parent::setUp();

        $bin = __DIR__ . '/../../../tools/dugdale';
        $this->port2 = $this->randomFreePortInternal();
        $base = sys_get_temp_dir() . '/letts-int2-' . uniqid();
        $this->dataDir2 = "$base/data";
        mkdir($this->dataDir2, 0o755, recursive: true);

        $yamlTpl = file_get_contents(__DIR__ . '/dugdale.yaml');
        $yaml = strtr($yamlTpl, [
            '${DATA_DIR}'    => $this->dataDir2,
            '${PORT}'        => (string) $this->port2,
            '${MISSION_DIR}' => $this->missionDir,
        ]);
        $cfg = "$base/dugdale.yaml";
        file_put_contents($cfg, $yaml);
        chmod($cfg, 0o600);

        $this->proc2 = proc_open(
            [$bin, '-config', $cfg],
            [0 => ['pipe', 'r'], 1 => ['file', "$base/stdout.log", 'w'], 2 => ['file', "$base/stderr.log", 'w']],
            $this->pipes2,
        );
        if (!is_resource($this->proc2)) {
            self::fail("failed to spawn second dugdale");
        }
        $this->waitForHealthz2();
        $this->applyConfigSecond();

        // Re-write letts.yaml to expose both dugdales with shared labels.
        file_put_contents($this->lettsYaml, <<<YAML
            dugdales:
              - id: s1
                host: 127.0.0.1
                port: $this->port
                token: t-dispatch
                admin_token: t-admin
                labels: [prod]
                lanes:
                  normal: {concurrency: 1}
              - id: s2
                host: 127.0.0.1
                port: $this->port2
                token: t-dispatch
                admin_token: t-admin
                labels: [prod]
                lanes:
                  normal: {concurrency: 1}
            YAML);
        chmod($this->lettsYaml, 0o600);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc2)) {
            $status = proc_get_status($this->proc2);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
                $deadline = microtime(true) + 10;
                while (microtime(true) < $deadline) {
                    $s = proc_get_status($this->proc2);
                    if (!$s['running']) break;
                    usleep(50_000);
                }
                $s = proc_get_status($this->proc2);
                if ($s['running']) {
                    posix_kill($status['pid'], SIGKILL);
                }
            }
            foreach ($this->pipes2 as $p) {
                if (is_resource($p)) fclose($p);
            }
            proc_close($this->proc2);
        }
        parent::tearDown();
    }

    private function randomFreePortInternal(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return $port;
    }

    private function waitForHealthz2(): void
    {
        $deadline = microtime(true) + 5;
        $http = HttpClient::create();
        while (microtime(true) < $deadline) {
            try {
                $r = $http->request('GET', "http://127.0.0.1:$this->port2/v1/healthz");
                if ($r->getStatusCode() === 200) {
                    return;
                }
            } catch (\Throwable) {}
            usleep(50_000);
        }
        self::fail("second dugdale did not become healthy within 5s on port $this->port2");
    }

    /**
     * Mirror DugdaleFixture::applyConfig() against $this->port2 with t-admin.
     */
    private function applyConfigSecond(): void
    {
        $body = [
            'mission_dir' => $this->missionDir,
            'labels'      => ['test'],
            'lanes'       => [
                'normal' => ['concurrency' => 1],
            ],
            'runtime' => [
                'mission_path_template' => '{mission}.php',
                'command_template'      => ['php', '{mission_path}'],
                'validate_mission_file' => true,
            ],
        ];
        $http = HttpClient::create();
        $r = $http->request('POST', "http://127.0.0.1:$this->port2/v1/admin/apply", [
            'headers' => ['Authorization' => 'Bearer t-admin'],
            'json'    => $body,
        ]);
        $status = $r->getStatusCode();
        if ($status !== 200) {
            self::fail("second dugdale apply failed: HTTP $status: " . $r->getContent(false));
        }
    }
}
