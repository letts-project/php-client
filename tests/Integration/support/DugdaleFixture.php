<?php
declare(strict_types=1);

namespace Letts\Tests\Integration\support;

use Letts\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Boot/teardown a real dugdale daemon for one test. Spawns
 * `./tools/dugdale -config <generated yaml>` on a random free port, polls
 * /v1/healthz until 200 (max 5s), exposes $this->client() pre-configured
 * with a letts.yaml pointing at the spawned daemon.
 */
abstract class DugdaleFixture extends TestCase
{
    protected int $port;
    protected string $dataDir;
    protected string $missionDir;
    protected string $lettsYaml;

    /** @var resource|null */
    private $proc = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        $bin = __DIR__ . '/../../../tools/dugdale';
        if (!is_executable($bin)) {
            self::markTestSkipped(
                "tools/dugdale missing; run: cd ../letts && go build -o ../letts-php/tools/dugdale ./cmd/dugdale",
            );
        }

        $this->port = $this->randomFreePort();
        $base = sys_get_temp_dir() . '/letts-int-' . uniqid();
        $this->dataDir = "$base/data";
        $this->missionDir = "$base/missions";
        mkdir($this->dataDir, 0o755, recursive: true);
        mkdir($this->missionDir, 0o755, recursive: true);
        $this->seedMissions($this->missionDir);

        $yamlTpl = file_get_contents(__DIR__ . '/dugdale.yaml');
        $yaml = strtr($yamlTpl, [
            '${DATA_DIR}'    => $this->dataDir,
            '${PORT}'        => (string) $this->port,
            '${MISSION_DIR}' => $this->missionDir,
        ]);
        $cfgPath = "$base/dugdale.yaml";
        file_put_contents($cfgPath, $yaml);
        chmod($cfgPath, 0o600);

        $this->lettsYaml = "$base/letts.yaml";
        file_put_contents($this->lettsYaml, <<<YAML
            dugdales:
              - id: local
                host: 127.0.0.1
                port: $this->port
                token: t-dispatch
                admin_token: t-admin
                labels: [test]
                lanes:
                  normal: {concurrency: 1}
            YAML);
        chmod($this->lettsYaml, 0o600);

        $cmd = [$bin, '-config', $cfgPath];
        $this->proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', "$base/stdout.log", 'w'], 2 => ['file', "$base/stderr.log", 'w']],
            $this->pipes,
        );
        if (!is_resource($this->proc)) {
            self::fail("failed to spawn dugdale: " . implode(' ', $cmd));
        }
        $this->waitForHealthz();
        $this->applyConfig();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
                $deadline = microtime(true) + 10;
                while (microtime(true) < $deadline) {
                    $s = proc_get_status($this->proc);
                    if (!$s['running']) break;
                    usleep(50_000);
                }
                $s = proc_get_status($this->proc);
                if ($s['running']) {
                    posix_kill($status['pid'], SIGKILL);
                }
            }
            foreach ($this->pipes as $p) {
                if (is_resource($p)) fclose($p);
            }
            proc_close($this->proc);
        }
    }

    /** @param array<string, mixed> $opts client options (request_timeout, retry_attempts, …) */
    protected function client(array $opts = []): Client
    {
        return Client::fromConfig($this->lettsYaml, $opts);
    }

    /**
     * Block until the mission's persisted status is `done`. The terminal
     * `done` event is appended just before the DB row flips to `done`, so a
     * client that acted the instant it saw the event could still observe
     * `running` — tests that mutate a just-finished mission must wait here.
     */
    protected function waitForDone(Client $c, string $id, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($c->getMission($id, host: 'local')?->status === 'done') {
                return;
            }
            usleep(50_000);
        }
        self::fail("mission $id did not reach status=done within {$timeout}s");
    }

    /**
     * Client whose letts.yaml points at $port instead of the daemon — used to
     * route traffic through a fault-injecting proxy (see FlakyProxy).
     *
     * @param array<string, mixed> $opts
     */
    protected function clientVia(int $port, array $opts = []): Client
    {
        $path = dirname($this->lettsYaml) . "/letts-via-$port.yaml";
        if (!is_file($path)) {
            $yaml = str_replace("port: $this->port", "port: $port", (string) file_get_contents($this->lettsYaml));
            file_put_contents($path, $yaml);
            chmod($path, 0o600);
        }
        return Client::fromConfig($path, $opts);
    }

    /**
     * Seed the mission_dir with all Fixtures/missions/*.php.
     *
     * Mission scripts use `require __DIR__ . '/../../../vendor/autoload.php'`
     * which assumes the script lives in tests/Fixtures/missions/. Once copied
     * into the temp mission_dir that relative path is wrong, so we rewrite the
     * require to point at the project's absolute vendor/autoload.php.
     */
    private function seedMissions(string $dir): void
    {
        $src = __DIR__ . '/../../Fixtures/missions';
        if (!is_dir($src)) return;
        $autoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
        if ($autoload === false) {
            self::fail('vendor/autoload.php not found; run composer install');
        }
        foreach (glob("$src/*.php") as $file) {
            $contents = file_get_contents($file);
            $rewritten = str_replace(
                "require __DIR__ . '/../../../vendor/autoload.php';",
                "require " . var_export($autoload, true) . ';',
                $contents,
            );
            file_put_contents($dir . '/' . basename($file), $rewritten);
        }
    }

    private function randomFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return $port;
    }

    private function waitForHealthz(): void
    {
        $deadline = microtime(true) + 5;
        $http = HttpClient::create();
        while (microtime(true) < $deadline) {
            try {
                $r = $http->request('GET', "http://127.0.0.1:$this->port/v1/healthz");
                if ($r->getStatusCode() === 200) {
                    return;
                }
            } catch (\Throwable) {}
            usleep(50_000);
        }
        self::fail("dugdale did not become healthy within 5s on port $this->port");
    }

    /**
     * POST /v1/admin/apply to provision mission_dir, runtime, and the
     * `normal` lane — these live in AppliedState, not dugdale.yaml.
     */
    private function applyConfig(): void
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
        $r = $http->request('POST', "http://127.0.0.1:$this->port/v1/admin/apply", [
            'headers' => ['Authorization' => 'Bearer t-admin'],
            'json'    => $body,
        ]);
        $status = $r->getStatusCode();
        if ($status !== 200) {
            self::fail("apply failed: HTTP $status: " . $r->getContent(false));
        }
    }
}
