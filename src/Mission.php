<?php
declare(strict_types=1);

namespace Letts;

use Letts\Exceptions\InterruptedException;
use Letts\Internal\Mission\ControlChannel;
use Letts\Internal\Mission\FileResolver;
use Letts\Internal\Mission\InputParser;
use Letts\Internal\Mission\OutputRegistry;
use Letts\Internal\Mission\ShutdownHandler;
use Letts\Internal\Mission\SignalHandler;
use Letts\Internal\Mission\StandaloneMode;

/**
 * Mission-side runtime. Two execution modes:
 *   - Dugdale mode (LETTS_MISSION_ID env set): fd 3 control channel, real
 *     signal/shutdown handlers, files materialized by dugdale at <workDir>/in.
 *   - Standalone mode (env unset): argv input parsing, stderr progress,
 *     stdout success JSON, out/<key> paths under cwd.
 */
final class Mission
{
    private function __construct(
        private readonly InputParser $input,
        private readonly ?FileResolver $files,
        private readonly ?ControlChannel $control,
        private readonly SignalHandler $signals,
        private readonly OutputRegistry $outputs,
        private readonly bool $standalone,
    ) {}

    public static function start(): self
    {
        $missionId = getenv('LETTS_MISSION_ID');
        if ($missionId === false || $missionId === '') {
            return self::startStandalone($_SERVER['argv'] ?? []);
        }

        $stdin = (string) file_get_contents('php://stdin');
        $envWork = (string) getenv('LETTS_WORKDIR');
        if ($envWork === '') {
            self::fatalConfig('LETTS_WORKDIR not set in dugdale mode');
        }

        // The mission stdin is the raw user-input JSON (or the
        // literal `null` for empty input) — NOT an envelope. Input-file
        // metadata is delivered out of band via env vars
        // LETTS_IN_<role>{,__SHA256,__SIZE}, not in stdin.
        $input = InputParser::fromString($stdin);
        $fileMap = FileResolver::parseEnv(getenv() ?: []);

        try {
            $control = ControlChannel::openFd3();
        } catch (\RuntimeException $e) {
            self::fatalConfig($e->getMessage());
        }

        $signals = SignalHandler::install();
        $outputs = new OutputRegistry($envWork);

        // Uncaught Throwable → emit a fail event with a distinct
        // reason='uncaught_exception' plus trace/file/line, so the
        // daemon can tell business throws from PHP fatals. Then exit non-zero.
        // The shutdown handler below skips its own emit once a terminal event
        // has been sent (finalEmitted), avoiding duplicate_final_event.
        set_exception_handler(static function (\Throwable $e) use ($control): void {
            if (!$control->finalEmitted()) {
                $control->emit([
                    'event'     => 'fail',
                    'message'   => $e->getMessage(),
                    'reason'    => 'uncaught_exception',
                    'details'   => [
                        'class' => $e::class,
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ],
                    'exit_code' => 1,
                ]);
                $control->close();
            }
            exit(1);
        });

        // ShutdownHandler reports {outcome: oom|crashed, fail_message, fail_details}.
        // Translate that into a fd 3 `fail` event.
        // Skip if Mission::success()/fail() already emitted a terminal event —
        // otherwise dugdale would record `duplicate_final_event`.
        ShutdownHandler::install(function (array $r) use ($control) {
            if ($control->finalEmitted()) {
                return;
            }
            $reason = $r['outcome'] === 'oom' ? 'php_memory_limit' : 'php_fatal_error';
            $control->emit([
                'event'     => 'fail',
                'message'   => $r['fail_message'],
                'reason'    => $reason,
                'details'   => $r['fail_details'],
                'exit_code' => 1,
            ]);
            $control->close();
        });

        return new self(
            input: $input,
            files: new FileResolver($envWork, $fileMap),
            control: $control,
            signals: $signals,
            outputs: $outputs,
            standalone: false,
        );
    }

    /** @param list<string> $argv */
    public static function startStandalone(array $argv): self
    {
        $cwd = getcwd() ?: '/';
        return new self(
            input: new InputParser(StandaloneMode::loadInput($argv)),
            files: new FileResolver($cwd, []),
            control: null,
            signals: new SignalHandler(),
            outputs: new OutputRegistry($cwd),
            standalone: true,
        );
    }

    // ---------- Input ----------

    public function input(string $path, mixed $default = null): mixed
    {
        if (func_num_args() > 1) {
            return $this->input->get($path, $default);
        }
        return $this->input->get($path);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->input->all();
    }

    public function has(string $path): bool
    {
        return $this->input->has($path);
    }

    // ---------- Files ----------

    public function file(string $key): string
    {
        // Standalone debugging has no dugdale to materialize files into
        // work/in, so the input value for the key IS the path — the caller
        // points it at a real local file when invoking the mission directly.
        if ($this->standalone) {
            $value = $this->input->all()[$key] ?? null;
            if (!is_string($value)) {
                throw new \InvalidArgumentException("no input file with key \"$key\" (standalone: input.$key must be a path)");
            }
            return $value;
        }
        if ($this->files === null) {
            throw new \RuntimeException('no FileResolver in this mission');
        }
        return $this->files->inputPath($key);
    }

    public function fileSize(string $key): int
    {
        if ($this->standalone) {
            $size = @filesize($this->file($key));
            return $size === false ? 0 : $size;
        }
        return (int) $this->files->fileInfo($key)['size'];
    }

    /** @return resource */
    public function fileStream(string $key)
    {
        $fh = fopen($this->file($key), 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot open file: $key");
        }
        return $fh;
    }

    /** @return array<string, array{path: string, size: int, sha256: string}> */
    public function files(): array
    {
        return $this->files?->files() ?? [];
    }

    // ---------- Progress / cancellation ----------

    public function progress(?float $value = null, ?string $message = null): void
    {
        if ($this->standalone) {
            fwrite(STDERR, StandaloneMode::formatProgress($value, $message));
            return;
        }
        $payload = ['event' => 'progress'];
        if ($value !== null) { $payload['value'] = $value; }
        if ($message !== null) { $payload['message'] = $message; }
        $this->control?->emit($payload);
    }

    public function checkSignal(): void
    {
        if ($this->signals->interruptRequested()) {
            throw new InterruptedException('mission interrupted by signal');
        }
    }

    /**
     * Register a callback to run when the mission receives SIGTERM/SIGINT (e.g. a
     * letts kill). Runs in addition to the interrupt flag that checkSignal()
     * polls — use it to cooperatively stop a long-running blocking call (a
     * parser, a download loop) that can't poll checkSignal() itself. The callback
     * receives the signal number; keep it short (it runs in the signal handler).
     */
    public function onInterrupt(\Closure $cb): void
    {
        $this->signals->onSignal($cb);
    }

    /** Test helper — sets the signal flag without sending an actual signal. */
    public function forceInterruptForTest(): void
    {
        $this->signals->handle(SIGTERM);
    }

    // ---------- Output files ----------

    public function outputPath(string $key): string
    {
        self::assertValidOutputKey($key);
        if ($this->files === null) {
            throw new \RuntimeException('no FileResolver in this mission');
        }
        return $this->files->outputPath($key);
    }

    public function outputFile(string $key): void
    {
        self::assertValidOutputKey($key);
        $this->outputs->register($key);
        if ($this->standalone) {
            fwrite(STDERR, "[output-file] key=$key\n");
            return;
        }
        // Mission emits {event: output_file, key: ...} on fd 3.
        // Dugdale collects and hashes the file itself; mission only registers.
        $this->control?->emit(['event' => 'output_file', 'key' => $key]);
    }

    // ---------- Termination ----------

    /** @param array<string, mixed>|null $return */
    public function success(?array $return = null): never
    {
        if ($return !== null && array_is_list($return) && $return !== []) {
            throw new \InvalidArgumentException(
                'success() return must be a JSON object (assoc array), not a list; wrap as ["items" => $list]',
            );
        }
        // Verify registered output files exist on disk before reporting success.
        // Dugdale collects and hashes them itself (CollectOutputs); we only need
        // to confirm presence so the user gets a clear error here rather than
        // an opaque missing_output from dugdale.
        $this->outputs->assertAllPresent();
        // An empty PHP array encodes as a JSON list `[]`, but dugdale requires
        // the success `return` to be a JSON object or null — `[]`
        // is rejected as event_protocol_error. Coerce empty → `{}`.
        $returnValue = $return === [] ? new \stdClass() : $return;
        if ($this->standalone) {
            echo json_encode($returnValue ?? new \stdClass(), JSON_PRETTY_PRINT) . "\n";
            exit(0);
        }
        // fd3 event schema: success event carries `return` only.
        // outputs are registered earlier via `output_file` events.
        $payload = ['event' => 'success'];
        if ($return !== null) {
            $payload['return'] = $returnValue;
        }
        $this->control?->emit($payload);
        $this->control?->close();
        exit(0);
    }

    /** @param array<string, mixed>|null $details */
    public function fail(string $message, int $exitCode = 1, ?array $details = null): never
    {
        // A failure must leave the process with a non-zero status. Exit 0
        // alongside a fail event is contradictory and the daemon would record
        // it as `fail_then_zero_exit` instead of a clean explicit failure.
        if ($exitCode === 0) {
            $exitCode = 1;
        }
        if ($this->standalone) {
            fwrite(STDERR, "$message\n");
            exit($exitCode);
        }
        // fd3 event schema: fail event uses `message` / `reason` /
        // `details` / `exit_code` field names (not `fail_*`).
        $payload = [
            'event'     => 'fail',
            'message'   => $message,
            'reason'    => 'explicit',
            'exit_code' => $exitCode,
        ];
        if ($details !== null) {
            $payload['details'] = $details;
        }
        $this->control?->emit($payload);
        $this->control?->close();
        exit($exitCode);
    }

    /**
     * Output keys must match the daemon's role/key contract:
     * ^[A-Za-z_][A-Za-z0-9_]{0,63}$ with no reserved `__` prefix and no path
     * separators. Validating here gives a clear local error instead of an
     * opaque event_protocol_error from the daemon, and prevents a stray `/`
     * from escaping <work>/out/.
     */
    private static function assertValidOutputKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $key) || str_starts_with($key, '__')) {
            throw new \InvalidArgumentException(
                "invalid output key \"$key\" (must match ^[A-Za-z_][A-Za-z0-9_]{0,63}$ and not start with __)",
            );
        }
    }

    private static function fatalConfig(string $msg): never
    {
        fwrite(STDERR, "letts mission misconfigured: $msg\n");
        exit(1);
    }
}
