# letts/php-client

PHP client and mission runtime for the [letts](https://git.eswyft.org/letts/letts) distributed task queue (successor to jobd).

The package has two halves:

- **Client** (`Letts\Client`) — application-side. Dispatches missions to `dugdale`
  daemons, follows their event streams, fans out across hosts, and manages
  running missions (kill/restart/delete/query).
- **Mission runtime** (`Letts\Mission`) — worker-side. A thin façade your mission
  scripts use to read input, stream progress, write output files, react to
  cancellation, and report success/failure over the daemon's fd-3 control
  channel.

A **dugdale** is the daemon that actually runs missions; a **mission** is a PHP
script it executes; a **lane** is a named concurrency queue on a dugdale; a
**label** tags dugdales so you can address them by capability instead of by id.

## Requirements

- PHP **8.3+**
- ext-curl, ext-pcntl, ext-posix (plus ext-sockets to run the test suite)
- Composer

## Install

```bash
composer require letts/php-client
```

---

## Concepts

### Addressing a target

Every `dispatch()` / `run()` call selects its target dugdale in one of three
ways (combining them throws `BadRequestException`):

| Mode | Argument | Lane | Meaning |
|------|----------|------|---------|
| **route** | `route: 'normal'` | from route | A named `(host, lane)` pair defined in `letts.yaml`. |
| **host** | `host: 's1'` or `host: 5` | `lane:` **required** | A dugdale id, or an alias that resolves to one. A numeric server id may be passed as an `int`; it is cast to its string alias key. |
| **match** | `match: ['prod']` | `lane:` **required** | Auto-select a dugdale carrying **all** the given labels **and** declaring the lane. |

When neither `route` nor `host` is given, the auto-select label filter resolves
in order: the call's `match:` → the client's `withMatch()` scope →
`selector.match` from `letts.yaml`. If no source provides a filter, the call
throws `BadRequestException`. The two *default* sources are auto-select scoping
only — they are ignored by (and never conflict with) explicit `route`/`host`
calls; only literally passing `match:` together with `route`/`host` is rejected.

With `match`, if several dugdales qualify, one is chosen at random (load
distribution); if none qualify, `NoMatchingDugdaleException` is thrown.

### Scopes & tokens

Calls authenticate with one of three token scopes, picked automatically:

- **dispatch** — `dispatch()`, `run()`, `runParallel()`, `runOnAll()`, `getMission()`
- **admin** — `listMissions()`, `kill()`, `restart()`, `delete()`
- **exec** — reserved; not used by the client today

Each dugdale's own token wins over the global `auth.*` fallback. See
[Configuration](#configuration-lettsyaml).

---

## Client

### Construction

```php
use Letts\Client;

$letts = Client::default();                 // discover letts.yaml (see cascade below)
$letts = Client::fromConfig('/path/letts.yaml');
$letts = Client::fromConfig('/path/letts.yaml', [
    'request_timeout'          => 30,             // per-request inactivity seconds (default 30)
    'connect_timeout'          => 5,              // connection-phase seconds (best-effort per platform)
    'max_connections_per_host' => 4,              // curl pool size  (default 4)
    'retry_attempts'           => 3,              // total tries on network/5xx (default 3)
    'retry_backoff'            => [100, 500, 2000],// backoff in ms between tries
    'ignore_proxy'             => false,          // ignore per-dugdale proxy: directives, dial directly
]);
```

Both factories accept an optional injected `HttpClientInterface` for tests, and
an optional `onLaunchFailure` observer (see [Failure observer](#failure-observer)).
Retries apply to network errors and `5xx` responses only — `4xx` (including
`429`) is treated as definitive and not retried.

### `dispatch()` — fire-and-forget

Returns the mission id immediately; does not wait for completion.

```php
$id = $letts->dispatch(
    mission: 'NotifyUser',
    host: 's7', lane: 'high',          // or route:, or match: with lane:
    input: ['user_id' => 1],
    files: ['photo' => '/tmp/pic.jpg'], // optional, see Input files
    timeout: '30s',                     // optional mission execution timeout
    missionId: null,                    // optional caller-supplied id (idempotency)
);
```

**Idempotency:** the mission id doubles as the `Idempotency-Key`. Re-dispatching
the same `missionId` returns the same mission; reusing it with a *different*
payload throws `ConflictException`.

**`tryDispatch()`** takes the identical arguments but returns `?string`: on a
launch failure it notifies the [failure observer](#failure-observer) and returns
`null` instead of throwing — for fire-and-forget bulk loops where one unreachable
dugdale must not abort the rest.

### `run()` — dispatch and wait

Dispatches, then follows the NDJSON event stream until the mission reaches a
terminal `done` event, and returns a [`RunResult`](#result-objects). If the
connection drops mid-flight the stream reconnects (resuming from the last seen
event) with a short backoff, up to 3 consecutive unproductive attempts — the
budget resets while events keep arriving, so long missions over restartable
connections aren't capped.

```php
$r = $letts->run(
    mission: 'GenerateThumbnails',
    route: 'normal',                    // addressing: route | host & lane | match & lane
    input: ['video_id' => 123],
);
echo $r->return['processed'];

// All options:
$r = $letts->run(
    mission: 'RenderReport',
    host: 's1', lane: 'normal',
    input: ['id' => 9],
    files: ['template' => '/tmp/t.html'],
    timeout: '5m',                      // mission-side execution limit (daemon)
    waitTimeout: '30s',                 // client-side wait deadline (ms/s/m/h)
    onProgress: fn(?float $v, ?string $m) => printf("%.0f%% %s\n", ($v ?? 0) * 100, $m),
    downloadOutputsTo: '/tmp/out',      // save mission output files into this dir
    throwOnFailure: true,               // default: non-success → MissionFailedException
    fetchLogs: false,                   // default: do NOT pull stdout/stderr (extra request)
);
```

Notes:

- **`throwOnFailure`** (default `true`): a non-success outcome raises
  `MissionFailedException`. Set `false` to inspect the `RunResult` instead.
- **`fetchLogs`** (default `false`): stdout/stderr are an extra round-trip, so
  `$r->logs` is empty unless you opt in. Log fetch failures degrade to empty
  logs, never fail the run.
- **`downloadOutputsTo`**: on success, each output file the mission registered is
  streamed to `<dir>/<role>` (never buffered whole in memory) and verified
  against the size/sha256 from the terminal event; a failed or short download
  raises `StagingException` and leaves no partial file behind.
- **`Client::downloadOutput($result, $key): string`** is the in-memory
  counterpart of `downloadOutputsTo`: it streams one registered output file
  straight into a string (same size/sha256 verification) instead of to disk.
  Use it for outputs small enough to hold in memory that you want as a value
  rather than a file on disk — no need to pass `downloadOutputsTo`. Throws
  `BadRequestException` if `$key` is not among the run's outputs, or
  `StagingException` if the download or verification fails (both extend
  `LettsException`).
- **`timeout`** is enforced by the daemon on the mission; **`waitTimeout`** is how
  long the client waits before giving up on the stream — past it `run()` throws
  `WaitTimeoutException` while the mission keeps running on the daemon.

### `runParallel()` and `runOnAll()` — fan-out

`runParallel()` runs many jobs concurrently over one multiplexed connection pool,
so wall-clock is the slowest job, not the sum. Results preserve input order.

```php
$results = $letts->runParallel([
    ['host' => 's1', 'lane' => 'manual', 'mission' => 'DiskUsage', 'input' => ['mount' => '/']],
    ['host' => 's2', 'lane' => 'manual', 'mission' => 'DiskUsage', 'input' => ['mount' => '/']],
]);
foreach ($results as $hr) {                // each is a HostResult
    if ($hr->isSuccess()) {
        echo "$hr->host: " . $hr->result->return['summary'] . "\n";
    } else {
        echo "$hr->host: " . ($hr->error->kind ?? 'fail') . "\n";
    }
}
```

Each job is an array with the same addressing keys as `run()`
(`route` | `host` | `match`, plus `lane`, `mission`, `input`, `files`, `timeout`).
Unlike `run()`, a stream that drops mid-flight is **not** reconnected — the job
surfaces a network `HostError`. Use it for short control-style missions.

Both fan-out calls take a `waitTimeout:`; jobs still unfinished at the deadline
surface a `HostError` of kind `timeout` (their missions keep running on the
daemons):

```php
$results = $letts->runParallel($jobs, waitTimeout: '2m');
```

`runOnAll()` fans out one mission to **every** dugdale that matches the labels and
declares the lane:

```php
$results = $letts->runOnAll(mission: 'FlushCache', lane: 'normal', match: ['prod']);
```

The label filter is required and resolves like auto-select (`match:` →
`withMatch()` scope → `selector.match`). With no filter from any source,
`runOnAll()` throws `NoMatchingDugdaleException` instead of silently hitting
every dugdale that happens to declare the lane.

### Mission control & queries

```php
$info = $letts->getMission($id);                 // ?MissionInfo; host omitted → search all dugdales
$info = $letts->getMission($id, host: 's1');

$list = $letts->listMissions(host: 's1', filters: ['status' => 'running']); // admin; host required
$letts->kill($id, signal: 'TERM', host: 's1');   // admin
$newId = $letts->restart($id, host: 's1');        // admin; returns the new mission id
$letts->delete($id, host: 's1', force: true);     // admin

$dugdales = $letts->dugdales(match: ['prod']);    // list<Config\Dugdale> matching labels
$scoped   = $letts->withMatch(['prod']);          // copy with a default auto-select label filter
```

`getMission()` returns `null` when the mission isn't found. `listMissions()`,
`kill()`, `restart()`, and `delete()` require an explicit `host` and an admin
token.

Unlike dispatch (whose `Idempotency-Key` makes re-sending safe), `kill()` and
`restart()` are **never auto-retried**: every successful restart enqueues a
brand-new mission, so re-sending after an ambiguous network failure could
double the work. On a `NetworkException` check state via `getMission()` before
trying again. `delete()` is idempotent and retries normally.

### Result objects

**`RunResult`** (`Letts\Result\RunResult`)

| Property | Type | Notes |
|----------|------|-------|
| `host` | `string` | dugdale id that ran the mission |
| `missionId` | `string` | |
| `outcome` | `string` | `success` \| `failed` \| `oom` \| `killed` \| `timeout` \| `crashed` \| `lost` |
| `return` | `?array` | the mission's `success()` payload |
| `failReason` / `failMessage` / `failDetails` | `?string` / `?string` / `?array` | populated on failure |
| `exitCode` / `signal` | `?int` / `?string` | |
| `durationMs` | `int` | |
| `logs` | `Logs` | empty unless `fetchLogs: true` |
| `outputFiles` | `array` | `role => {staging_id, sha256, size}` |

`->isSuccess(): bool` is true iff `outcome === 'success'`.

**`Logs`** — `stdout`, `stderr` (strings), `stdoutTruncated`, `stderrTruncated`.

**`HostResult`** (returned by `runParallel()`/`runOnAll()`) — `host`,
`result: ?RunResult`, `error: ?HostError`; helpers `->isReachable()` and
`->isSuccess()`.

**`HostError`** — `kind` (`auth` \| `bad_request` \| `conflict` \| `backpressure` \| `network` \| `timeout`), `message`, `httpStatus`, `errorCode`.

**`MissionInfo`** (`getMission()`/`listMissions()`) — the full daemon record:
`missionId`, `status`, `outcome`, `lane`, `missionName`, `groupId`, `exitCode`,
`signal`, `failReason`/`failMessage`/`failDetails`, `return`, `input`,
`durationMs`, `timeoutMs`, `pid`, `time{Created,Started,Finished}Ms`,
`restartedFrom`, `inputs`, `outputs`, and more.

### Exceptions

All extend `Letts\Exceptions\LettsException` (which extends `\RuntimeException`).

| Exception | Raised when |
|-----------|-------------|
| `MissionFailedException` | `run(throwOnFailure: true)` and the mission did not succeed. Carries `getOutcome()`, `getReason()`, `getFailMessage()`, `getFailDetails()`, `getResult()`. |
| `NoMatchingDugdaleException` | `match`/`runOnAll` finds no dugdale with the requested labels and lane. |
| `BadRequestException` | invalid addressing (route and host combined, no addressing at all, missing lane), `400`. |
| `AuthException` | `401` — bad/missing token for the scope. |
| `ConflictException` | `409` — idempotency-key reused with a different payload. |
| `BackpressureException` | `503` — daemon shedding load. |
| `DispatchException` | other non-2xx; `getCode()` is the HTTP status. |
| `NetworkException` | transport failure (incl. an event stream that can't be kept open to a terminal event); `getHost()` identifies the dugdale. |
| `WaitTimeoutException` | `run(waitTimeout:)` elapsed before the mission finished; the mission keeps running on the daemon. |
| `StagingException` | input-file upload or output-file download failed. |
| `ConfigException` / `MissingEnvException` | bad `letts.yaml` / unresolved `${ENV}` (the latter exposes `->name`). |

In `runParallel()`/`runOnAll()` these dispatch errors are caught per-job and
surfaced as `HostError` instead of thrown.

### Failure observer

A task that fails to *launch* (dugdale unreachable, network/auth/config error, a
bad input file) surfaces as an exception at the call site. In a large codebase
with hundreds of call sites, wrapping each in `try/catch` just to leave a trace
is error-prone. Register one observer instead:

```php
$letts = Client::fromConfig('/path/letts.yaml', onLaunchFailure: function (Letts\Result\LaunchFailure $f) {
    $logger->error("letts {$f->method} failed at {$f->phase}: {$f->exception->getMessage()}", [
        'mission' => $f->mission, 'host' => $f->host,
        'missionId' => $f->missionId, 'retryable' => $f->retryable,
    ]);
    if ($f->retryable && $f->phase === 'dispatch') {
        $retryQueue->push($f);              // $f carries mission/input/files to re-dispatch
    }
});
// or scoped, leaving the original untouched: $logging = $letts->withFailureObserver($observer);
```

It fires for every launch failure of `run()`, `dispatch()`, `tryDispatch()`, and
per failed host of `runParallel()`/`runOnAll()`. It does **not** fire for
`MissionFailedException` (the mission ran — that's a result) or
`WaitTimeoutException` (it is still running). The observer is a pure side-effect
channel: **control flow is unchanged** — `run()`/`dispatch()` still throw,
`tryDispatch()` still returns `null` — and any exception it throws is swallowed
(routed to `error_log`), so it can never break a call. Durability of the
observer's own work (e.g. writing a retry record) is the caller's responsibility.

`LaunchFailure` carries the original `exception`; a `retryable` flag (transient
network/`5xx` vs deterministic); `method`; `phase` — `dispatch` (never started,
re-dispatchable) or `stream` (already running on the daemon, reconnect instead of
re-dispatching); and the full launch descriptor (`mission`, `route`, `host`,
`match`, `lane`, `input`, `files`, `timeout`, `missionId`) — enough to recreate
the task. `missionId` is assigned before the request, so re-dispatching with it
is idempotent. Config-level fan-out failures (`ConfigException`,
`NoMatchingDugdaleException` from `runParallel`/`runOnAll`) propagate as thrown
exceptions rather than per-host observer calls.

---

## Mission runtime

A mission is an executable PHP script the dugdale runs. Bootstrap with
`Mission::start()`, which auto-detects its environment: under a dugdale
(`LETTS_MISSION_ID` set) it wires the fd-3 control channel and signal/shutdown
handlers; run directly from a shell it falls back to [standalone mode](#standalone-debug-mode).

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Letts\Mission;

$m = Mission::start();

$videoId = $m->input('video_id');
$m->progress(0.5, "processing video $videoId");
// ... do work ...
$m->success(['processed' => true]);
```

### Input

Input is a JSON object, read with dot-notation paths:

```php
$m->input('user.name');          // throws if the path is absent
$m->input('user.name', 'guest'); // returns the default if absent
$m->has('user.name');            // bool
$m->all();                       // the whole input array
```

### Input files

Files passed via `dispatch(files: ...)` / `run(files: ...)` are uploaded to the
daemon's staging area (resumable: an interrupted upload retries from the byte
the daemon confirmed) and materialized on disk for the mission, keyed by the
**role** you chose:

```php
$path = $m->file('photo');       // absolute path to the materialized file
$size = $m->fileSize('photo');
$fh   = $m->fileStream('photo'); // open read handle
$all  = $m->files();             // role => {path, size, sha256}
```

### Progress & cooperative cancellation

```php
$m->progress(0.25, 'a quarter done');   // value (0..1) and/or message; both optional
$m->progress(message: 'still working');
```

`Mission::start()` installs SIGTERM/SIGINT handlers with
`pcntl_async_signals(true)`, so signals arrive between opcodes with no extra
work. Call `$m->checkSignal()` at safe points in long loops — it throws
`InterruptedException` when the daemon asks the mission to stop:

```php
foreach ($items as $item) {
    $m->checkSignal();   // throws InterruptedException on SIGTERM/SIGINT
    process($item);
}
```

If you let it propagate, the runtime reports the mission as failed. In practice
the stop was initiated by the daemon (a `kill` or timeout), so the daemon's
terminal outcome (`killed`/`timeout`) takes precedence regardless of what the
mission emits last. Don't add manual `pcntl_signal_dispatch()` calls — async
delivery is already on.

### Output files

Write into the path the runtime gives you, then register the key. Keys must match
`^[A-Za-z_][A-Za-z0-9_]{0,63}$` and not start with `__`:

```php
file_put_contents($m->outputPath('result'), $bytes);
$m->outputFile('result');        // register; dugdale collects and hashes it on success
$m->success(['written' => true]);
```

`success()` verifies every registered output file exists on disk first, so a
missing file fails locally with a clear error instead of an opaque daemon-side
`missing_output`.

### Success & failure

```php
$m->success(['key' => 'value']);  // return must be a JSON object (assoc array) or null — not a list
$m->success();                     // no return value
$m->fail('could not reach upstream', exitCode: 2, details: ['url' => $url]);
```

Both terminate the process. Passing a list (sequential array) to `success()`
throws `InvalidArgumentException` — wrap it, e.g. `['items' => $list]`. A
failure always exits non-zero: `fail(..., exitCode: 0)` is coerced to `1`
(exit 0 would contradict the failure and the daemon would record it as the
diagnostic `fail_then_zero_exit` instead of your message).

### Failure & crash semantics

The runtime classifies abnormal exits for you:

- **Uncaught `Throwable`** → fail event, `reason = uncaught_exception`, with class/file/line/trace in `failDetails`.
- **Out of memory** → `outcome = oom`, `reason = php_memory_limit` (a 64 KB reserve buffer lets the handler run after the limit is hit).
- **Other fatal error** → `outcome = crashed` / `reason = php_fatal_error`.
- **Explicit `fail()`** → `reason = explicit`.

### Standalone debug mode

Run a mission outside a dugdale for local debugging. Input comes from argv,
progress goes to stderr, and the `success()` payload is printed as pretty JSON to
stdout:

```bash
php missions/X.php --input='{"video_id":42}'
php missions/X.php --input-file=payload.json
php missions/X.php --input=-            # read JSON from stdin
```

File keys work too: with no dugdale to materialize files, `$m->file('photo')`
returns the input value itself as the path — point it at a local file, e.g.
`--input='{"photo":"/tmp/photo.jpg"}'`. Output files go to `out/<key>` under
the current directory.

---

## Configuration (`letts.yaml`)

The client discovers `letts.yaml` via this cascade (first existing file wins),
matching the `letts` CLI so the library and CLI resolve the same file:

1. `$LETTS_CONFIG` (if set, the file **must** exist)
2. `./letts.yaml`
3. `$XDG_CONFIG_HOME/letts/letts.yaml` (only when `XDG_CONFIG_HOME` is set)
4. `~/.letts/letts.yaml`
5. `/etc/letts/letts.yaml`

Full example:

```yaml
# Global token fallbacks (a dugdale's own token overrides these).
# ${ENV} placeholders are substituted when a token/alias is resolved.
auth:
  token:       "${LETTS_DISPATCH_TOKEN}"   # dispatch scope
  admin_token: "${LETTS_ADMIN_TOKEN}"      # admin scope

defaults:
  port: 7180                # used by any dugdale that omits `port`

# Default label filter for auto-select / runOnAll when a call passes no match:.
selector:
  match: [prod]

# Named (host, lane) pairs addressable via run(route: ...).
routes:
  normal: {host: s1, lane: normal}
  bulk:   {host: s1, lane: high}

# Host aliases resolved to a dugdale id (cycle-checked, max 8 hops; ${ENV} ok).
# Alias keys may lead with a digit, so a numeric server id works as a key
# (host: 5 → looked up as "5").
aliases:
  primary: s1
  5: s5

# Reusable blocks dugdales can `extends`.
templates:
  prod:
    labels: [prod]
    lanes:
      normal: {concurrency: 4}
      high:   {concurrency: 8}

dugdales:
  - id: s1
    host: server1.internal
    port: 7180
    extends: prod                  # inherit labels and lanes
    token: "${S1_TOKEN}"           # overrides auth.token for s1
    admin_token: "${S1_ADMIN}"
  - id: s2
    host: server2.internal
    extends: prod
    lanes:
      high: null                   # delete the `high` lane inherited from the template
  - id: s3
    host: server3.internal
    extends: prod
    proxy: "socks5h://127.0.0.1:1080"  # reach this dugdale through a SOCKS5 proxy
```

### Reaching a dugdale through a proxy

A dugdale (or a template it `extends`) may declare a `proxy:` so that **every**
connection to that dugdale — dispatch, the `run` event stream, parallel
fan-out, staging upload and download, admin calls — tunnels through it. Dugdales
without a `proxy` connect directly.

```yaml
templates:
  proxied:
    proxy: "socks5h://127.0.0.1:1080"   # shared by every dugdale that extends it
dugdales:
  - id: s3
    host: server3.internal
    extends: proxied                     # inherits the proxy
  - id: s4
    url: https://letts-s4.example.com
    proxy: "http://10.0.0.9:3128"        # its own proxy overrides any inherited one
```

- Accepted schemes are `socks5://`, `socks5h://`, `http://`, and `https://`; any
  other scheme is a config error. (An HTTP proxy such as Squid usually listens
  on `:3128` — use `http://`, not `socks5://`, for it.) Credentials may be
  embedded (`socks5h://user:pass@host:port`; percent-encode special characters).
  `${ENV}` placeholders are substituted at use.
- For SOCKS, DNS is always resolved **at the proxy**: `socks5://` is normalized
  to `socks5h://` before the request, matching the Go client. Write `socks5h://`.
- A dugdale's own `proxy` overrides the one it inherits via `extends`.
- Pass `['ignore_proxy' => true]` to `Client::fromConfig()` to ignore all
  `proxy:` directives and connect to dugdales directly.

Key reference:

| Key | Purpose |
|-----|---------|
| `auth.token` / `admin_token` / `exec_token` | global token fallbacks per scope |
| `defaults.port` | default daemon port (`0–65535`) when a dugdale omits one |
| `selector.match` | default label filter for auto-select / `runOnAll` when no `match:` is passed |
| `routes.<name>` | `{host, lane}` for `run(route: ...)` |
| `aliases.<name>` | alternate name → dugdale id |
| `templates.<name>` | reusable `{labels, lanes, tokens, …}` block |
| `dugdales[].id` | **required**; unique; `^[a-z][a-z0-9_-]{0,63}$` |
| `dugdales[].host` / `port` / `url` | endpoint (`url` overrides `host:port`) |
| `dugdales[].proxy` | `socks5://` / `socks5h://` / `http://` / `https://` URL to tunnel all connections to this dugdale through (inheritable via `extends`) |
| `dugdales[].extends` | name of a template to inherit from |
| `dugdales[].labels` | tags used by `match` / `runOnAll` / `dugdales()` |
| `dugdales[].lanes.<name>` | `{concurrency, paused}`; `null` deletes an inherited lane |
| `dugdales[].token` / `admin_token` / `exec_token` | per-dugdale tokens |

**`extends` merge:** scalars are dugdale-wins; `labels` are **replaced** (not
unioned) when the dugdale sets its own; `lanes` are unioned with the template
(dugdale wins on collision, `null` deletes). Unknown keys are rejected, and all
ids/lane/label/route/template names are regex-validated on load.

> The `mission_dir` and `runtime` keys are accepted for parity with the `letts`
> CLI config but are not consumed by the PHP client (they configure the daemon,
> not the client).

---

## Testing

```bash
composer test                # unit tests only — no daemon needed
composer test:integration    # rebuilds tools/dugdale from source, then runs
composer test:all
```

`composer test:integration` first runs `composer build-daemon`, which rebuilds
`tools/dugdale` from the letts Go source **every time** — so integration tests
always run against the current daemon wire-contract, never a stale binary. By
default it expects the letts checkout at `../letts`; override with:

```bash
LETTS_SRC=/path/to/letts composer test:integration
```

If the Go toolchain or the letts source is unavailable, the rebuild is skipped
and integration tests that need the binary are skipped too. You can rebuild the
daemon on its own with `composer build-daemon`.

## License

MIT — see `LICENSE`.
