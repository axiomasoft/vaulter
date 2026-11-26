#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Laravel 13 persistent Octane-style HTTP worker + PostgreSQL RLS + OpenTelemetry benchmark.
 *
 * Enforces storage.md §S4 & engineering-standards.md §3.8:
 *   - Boots a persistent Laravel 13 HTTP kernel as non-bypass role `vaulter_runtime` (NOBYPASSRLS)
 *     with `vaulter.tenancy.enabled=true` and `vaulter.tenancy.rls=true`.
 *   - Exercises the tenant-scoped API route `GET /api/vaulter/v1/drives/{drive}/nodes` across
 *     two tenants (101 & 202), verifying RLS isolation (positive per-tenant rows + cross-tenant 404).
 *   - Runs two independent passes (OTel off vs OTel on with W3C `traceparent`), verifying exported
 *     span counts in both passes (`baseline_exported_spans === 0`, `otel_exported_spans >= requests`).
 *   - Measures actual HTTP server worker `/proc/<pid>/status` `VmRSS` / `VmHWM` (never client RSS).
 *   - SLO: p95 <= 65 ms and OTel p95 overhead < 2.0 ms; exit code strictly matches MEASURED_PASS.
 */

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Load\Listeners\FlushTraceExporter;
use Vaulter\Load\LoadServiceProvider;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\VaulterServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class BenchHeaderTenantResolver implements TenantResolver
{
    public static int|string|null $forcedTenant = null;

    public function current(): int|string|null
    {
        if (self::$forcedTenant !== null) {
            return self::$forcedTenant;
        }
        if (function_exists('app') && app()->bound('request')) {
            $raw = app('request')->header('X-Tenant-Id');
            if (is_string($raw) && $raw !== '') {
                return ctype_digit($raw) ? (int) $raw : $raw;
            }
        }

        return null;
    }
}

final class BenchRlsTransactionMiddleware
{
    public function __construct(private readonly VaulterDatabase $db) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->header('X-Tenant-Id', '') === '') {
            return response()->json(['error' => 'missing_tenant_header'], 400);
        }

        return $this->db->transaction(static fn () => $next($request));
    }
}

/** @return array<string, string|true> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$k, $v] = explode('=', $body, 2);
            $out[$k] = $v;
        } else {
            $out[$body] = true;
        }
    }

    return $out;
}

function findPgBin(): string
{
    foreach ([getenv('PG_BIN'), '/usr/lib/postgresql/18/bin', '/usr/lib/postgresql/17/bin', '/usr/lib/postgresql/16/bin'] as $candidate) {
        if (is_string($candidate) && is_file($candidate.'/initdb') && is_file($candidate.'/pg_ctl')) {
            return $candidate;
        }
    }
    throw new RuntimeException('PostgreSQL 18 initdb/pg_ctl not found; set PG_BIN.');
}

function readProcStatusMiB(int $pid): array
{
    $raw = @file_get_contents("/proc/{$pid}/status");
    $rssKb = 0;
    $hwmKb = 0;
    if (is_string($raw)) {
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $raw, $m) === 1) {
            $rssKb = (int) $m[1];
        }
        if (preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $raw, $m) === 1) {
            $hwmKb = (int) $m[1];
        }
    }

    return [
        'rss_mib' => round($rssKb / 1024.0, 2),
        'hwm_mib' => round($hwmKb / 1024.0, 2),
    ];
}

function stats(array $samplesMs): array
{
    sort($samplesMs);
    $n = count($samplesMs);
    $p = static function (float $q) use ($samplesMs, $n): float {
        if ($n === 0) {
            return 0.0;
        }
        $idx = (int) ceil(($q / 100.0) * $n) - 1;

        return round($samplesMs[max(0, min($n - 1, $idx))], 3);
    };

    return [
        'count' => $n,
        'min_ms' => round(min($samplesMs), 3),
        'p50_ms' => $p(50.0),
        'p95_ms' => $p(95.0),
        'p99_ms' => $p(99.0),
        'max_ms' => round(max($samplesMs), 3),
    ];
}

function bootVaulterApp(string $pgHost, string $dbName, string $pgUser, string $pgPass, string $storageDir, bool $enableOtel, ?string $tracePath): \Illuminate\Foundation\Application
{
    $app = (new class($pgHost, $dbName, $pgUser, $pgPass, $storageDir) {
        use CreatesApplication;

        public function __construct(
            private readonly string $pgHost,
            private readonly string $dbName,
            private readonly string $pgUser,
            private readonly string $pgPass,
            private readonly string $storageDir,
        ) {}

        protected function getPackageProviders($app): array
        {
            return [
                VaulterServiceProvider::class,
                VaulterMediaServiceProvider::class,
                VaulterDriveServiceProvider::class,
                VaulterDocumentsServiceProvider::class,
                LoadServiceProvider::class,
            ];
        }

        protected function defineEnvironment($app): void
        {
            putenv('CACHE_STORE=array');
            putenv('QUEUE_CONNECTION=sync');
            $app['config']->set('app.env', 'local');
            $app['config']->set('app.debug', false);
            $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('queue.default', 'sync');
            $app['config']->set('broadcasting.default', 'null');
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => $this->pgHost,
                'port' => 5432,
                'database' => $this->dbName,
                'username' => $this->pgUser,
                'password' => $this->pgPass,
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'disable',
            ]);
            $app['config']->set('vaulter.ids.strategy', 'bigint');
            $app['config']->set('vaulter.tenancy.enabled', true);
            $app['config']->set('vaulter.tenancy.rls', true);
            $app['config']->set('vaulter.tenancy.resolver', BenchHeaderTenantResolver::class);
            $app['config']->set('vaulter-media.disk', 'local');
            $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => $this->storageDir]);
        }
    })->createApplication();

    // Force array/sync drivers and NullMetricsRecorder in case LoadServiceProvider overrides config for docker stand.
    $app['config']->set('cache.default', 'array');
    $app['config']->set('session.driver', 'array');
    $app['config']->set('queue.default', 'sync');
    $app['config']->set('broadcasting.default', 'null');
    $app['config']->set('vaulter.observability.recorder', \Vaulter\Observability\NullMetricsRecorder::class);
    $router = $app->make('router');
    $router->aliasMiddleware('bench.rls_tx', BenchRlsTransactionMiddleware::class);
    $router->prependMiddlewareToGroup('api', 'bench.rls_tx');
    $router->prependMiddlewareToGroup('api', 'vaulter.tenant');

    $app->singleton(MetricsRecorder::class, \Vaulter\Observability\NullMetricsRecorder::class);
    $app->singleton(\Vaulter\Load\Observability\LoadExpositionRecorder::class, \Vaulter\Observability\NullMetricsRecorder::class);
    $recorder = $enableOtel
        ? OpenTelemetryTraceRecorder::create(ratio: 1.0, exportPath: $tracePath)
        : OpenTelemetryTraceRecorder::create(ratio: 0.0, exportPath: null);
    $app->instance(OpenTelemetryTraceRecorder::class, $recorder);
    $app->instance(TraceRecorder::class, $recorder);
    foreach ([
        \Vaulter\Http\Middleware\RecordVaulterRequestMetrics::class,
        \Vaulter\Observability\TelemetryMiddleware::class,
        \Vaulter\Load\Listeners\RecordThrottledResponse::class,
        \Vaulter\Load\Listeners\RecordBroadcastQueueWait::class,
        \Vaulter\Load\Listeners\RecordFailedJob::class,
        \Vaulter\Load\Listeners\RecordCommandPeakMemory::class,
    ] as $singleton) {
        $app->forgetInstance($singleton);
    }
    $router->prependMiddlewareToGroup('api', OpenTelemetryTraceRecorder::class);

    return $app;
}

function sendHttpRequest($socket, string $path, array $headers): array
{
    $rawHeaders = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: application/json\r\nConnection: keep-alive\r\n";
    foreach ($headers as $k => $v) {
        $rawHeaders .= "{$k}: {$v}\r\n";
    }
    $rawHeaders .= "\r\n";
    if (fwrite($socket, $rawHeaders) === false) {
        throw new RuntimeException('HTTP write failed');
    }

    $headerBuf = '';
    while (!str_contains($headerBuf, "\r\n\r\n")) {
        $chunk = fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('HTTP response truncated while reading headers');
        }
        $headerBuf .= $chunk;
    }
    [$head, $bodyStart] = explode("\r\n\r\n", $headerBuf, 2);
    $lines = explode("\r\n", $head);
    preg_match('#^HTTP/1\.[01]\s+(\d+)#', $lines[0], $statusMatch);
    $status = isset($statusMatch[1]) ? (int) $statusMatch[1] : 0;
    $contentLength = 0;
    $workerPid = 0;
    foreach (array_slice($lines, 1) as $line) {
        if (stripos($line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr($line, strlen('Content-Length:')));
        } elseif (stripos($line, 'X-Worker-Pid:') === 0) {
            $workerPid = (int) trim(substr($line, strlen('X-Worker-Pid:')));
        }
    }
    $body = $bodyStart;
    while (strlen($body) < $contentLength) {
        $need = $contentLength - strlen($body);
        $chunk = fread($socket, $need);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('HTTP response body truncated');
        }
        $body .= $chunk;
    }

    return ['status' => $status, 'body' => $body, 'worker_pid' => $workerPid];
}

function countExportedSpans(?string $traceFile, array $expectedTraceIds = []): int
{
    if ($traceFile === null || $traceFile === '' || !is_file($traceFile)) {
        return 0;
    }
    $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    if ($expectedTraceIds === []) {
        return count($lines);
    }
    $matched = 0;
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['trace_id']) && isset($expectedTraceIds[$row['trace_id']])) {
            $matched++;
        }
    }

    return $matched;
}

function assertTenantResponse(array $resp, string $expectedNeedle, string $forbiddenNeedle, string $context): void
{
    if ($resp['status'] !== 200) {
        throw new RuntimeException("{$context}: expected HTTP 200, got {$resp['status']} body={$resp['body']}");
    }
    $decoded = json_decode($resp['body'], true);
    $names = array_column($decoded['data'] ?? [], 'name');
    if (!in_array($expectedNeedle, $names, true)) {
        throw new RuntimeException("{$context}: missing tenant marker '{$expectedNeedle}' in ".json_encode($names));
    }
    foreach ($names as $name) {
        if (str_contains((string) $name, $forbiddenNeedle)) {
            throw new RuntimeException("{$context}: CROSS-TENANT RLS LEAK detected ('{$name}')");
        }
    }
}

$args = parseArgs($argv);
$mode = isset($args['dry-run']) || ($args['mode'] ?? null) === 'dry-run'
    ? 'dry-run'
    : ((isset($args['smoke']) || ($args['mode'] ?? null) === 'smoke') ? 'smoke' : 'full');
$liveUrl = is_string($args['url'] ?? null) ? trim($args['url']) : null;
$requests = (int) ($args['requests'] ?? ($mode === 'smoke' ? 80 : 300));
$warmup = (int) ($args['warmup'] ?? ($mode === 'smoke' ? 20 : 60));
$outputFile = is_string($args['output'] ?? null) ? $args['output'] : null;

if ($mode === 'dry-run') {
    $out = [
        'status' => 'DRY_RUN',
        'benchmark' => 'octane_rls_and_otel_overhead',
        'mode' => 'dry-run',
        'requests' => $requests,
        'warmup' => $warmup,
        'route' => 'GET /api/vaulter/v1/drives/{drive}/nodes',
        'runtime_role' => 'vaulter_runtime (NOBYPASSRLS + FORCE ROW LEVEL SECURITY)',
        'slo' => ['p95_ms_max' => 65.0, 'otel_p95_overhead_ms_max' => 2.0],
        'url_override' => $liveUrl,
    ];
    $encoded = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if ($outputFile !== null) {
        @mkdir(dirname($outputFile), 0777, true);
        file_put_contents($outputFile, $encoded);
    }
    echo $encoded;
    exit(0);
}

// Live URL mode against external Octane HTTP stand (--url=http://127.0.0.1:8080 --drive-a=... --drive-b=... --worker-pid=...).
if ($liveUrl !== null && $liveUrl !== '') {
    $path = (string) (parse_url($liveUrl, PHP_URL_PATH) ?: '');
    if ($path === '' || preg_match('#^/(load/health|health|up|ready)/?$#i', $path) === 1) {
        fwrite(STDERR, "Refusing non-RLS static health endpoint in --url mode: {$liveUrl}\n");
        exit(1);
    }
    $driveA = (string) ($args['drive-a'] ?? '');
    $driveB = (string) ($args['drive-b'] ?? '');
    $workerPid = (int) ($args['worker-pid'] ?? 0);
    $traceFile = is_string($args['trace-file'] ?? null) ? $args['trace-file'] : null;
    if ($driveA === '' || $driveB === '' || $workerPid <= 0 || !is_file("/proc/{$workerPid}/status")) {
        fwrite(STDERR, "--url mode requires --drive-a, --drive-b, and live --worker-pid=/proc/<pid> for server RSS.\n");
        exit(1);
    }
    $parsedUrl = parse_url($liveUrl);
    $host = (string) ($parsedUrl['host'] ?? '127.0.0.1');
    $port = (int) ($parsedUrl['port'] ?? 80);
    $tenantA = (string) ($args['tenant-a'] ?? '101');
    $tenantB = (string) ($args['tenant-b'] ?? '202');
    $actorA = (string) ($args['actor-a'] ?? '1011');
    $actorB = (string) ($args['actor-b'] ?? '2022');
    $needleA = (string) ($args['needle-a'] ?? 'tenant-101-vault-alpha');
    $needleB = (string) ($args['needle-b'] ?? 'tenant-202-vault-gamma');
    $forbidA = (string) ($args['forbid-a'] ?? 'tenant-202-');
    $forbidB = (string) ($args['forbid-b'] ?? 'tenant-101-');

    $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
    if ($sock === false) {
        fwrite(STDERR, "Failed connecting to {$host}:{$port}: {$errstr}\n");
        exit(1);
    }
    $cross = sendHttpRequest($sock, "/api/vaulter/v1/drives/{$driveB}/nodes", ['X-Tenant-Id' => $tenantA, 'X-Load-Actor' => $actorA]);
    if ($cross['status'] !== 404) {
        fclose($sock);
        fwrite(STDERR, "Cross-tenant isolation check failed in --url mode: HTTP {$cross['status']}\n");
        exit(1);
    }
    $samples = [];
    for ($i = 0; $i < $requests; $i++) {
        $isA = ($i % 2) === 0;
        $t0 = hrtime(true);
        $resp = sendHttpRequest(
            $sock,
            '/api/vaulter/v1/drives/'.($isA ? $driveA : $driveB).'/nodes',
            ['X-Tenant-Id' => $isA ? $tenantA : $tenantB, 'X-Load-Actor' => $isA ? $actorA : $actorB]
        );
        $samples[] = (hrtime(true) - $t0) / 1_000_000.0;
        assertTenantResponse($resp, $isA ? $needleA : $needleB, $isA ? $forbidA : $forbidB, 'live_http');
    }
    fclose($sock);
    $liveStats = stats($samples);
    $procStats = readProcStatusMiB($workerPid);
    $pass = $liveStats['p95_ms'] <= 65.0;
    $report = [
        'status' => $pass ? 'MEASURED_PASS' : 'MEASURED_FAIL',
        'mode' => 'live_http_rls',
        'url' => $liveUrl,
        'requests' => $requests,
        'stats' => $liveStats,
        'worker_rss_mib' => $procStats['rss_mib'],
        'worker_hwm_mib' => $procStats['hwm_mib'],
        'traces' => $traceFile ? ['exported_spans' => countExportedSpans($traceFile)] : null,
    ];
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if ($outputFile !== null) {
        @mkdir(dirname($outputFile), 0777, true);
        file_put_contents($outputFile, $encoded);
    }
    echo $encoded;
    exit($pass ? 0 : 1);
}

// Self-contained Laravel 13 persistent worker + real PG18 RLS (`vaulter_runtime` NOBYPASSRLS) benchmark.
try {
$pgBin = findPgBin();
$workBase = sys_get_temp_dir().'/vaulter-octane-rls-'.getmypid();
$sockDir = strlen($workBase) > 72 ? sys_get_temp_dir().'/vpgrls-'.getmypid() : $workBase;
if (strlen($sockDir) > 72) {
    $sockDir = '/tmp/vpgrls-'.getmypid();
}
$dbDir = $workBase.'/db';
$storageDir = $workBase.'/storage';
$traceBaseline = $workBase.'/traces-baseline.jsonl';
$traceOtel = $workBase.'/traces-otel.jsonl';
@mkdir($dbDir, 0775, true);
@mkdir($sockDir, 0775, true);
@mkdir($storageDir, 0775, true);

$pgUser = get_current_user() ?: 'postgres';
$dbName = 'vaulter_bench';
$runtimeRole = 'vaulter_runtime';
$runtimePass = 'bench-runtime-secret';
$pgStarted = false;

$parentPid = getmypid();
$cleanup = static function () use ($pgBin, $dbDir, $workBase, $sockDir, &$pgStarted, $parentPid): void {
    if (getmypid() !== $parentPid) {
        return;
    }
    if ($pgStarted && is_file($dbDir.'/postmaster.pid')) {
        exec(escapeshellarg($pgBin.'/pg_ctl').' -D '.escapeshellarg($dbDir).' -m fast -w stop >/dev/null 2>&1');
    }
    if (is_dir($workBase)) {
        exec('rm -rf '.escapeshellarg($workBase));
    }
    if ($sockDir !== $workBase && is_dir($sockDir)) {
        exec('rm -rf '.escapeshellarg($sockDir));
    }
};
register_shutdown_function($cleanup);

exec(escapeshellarg($pgBin.'/initdb').' -D '.escapeshellarg($dbDir).' --no-locale --encoding=UTF8 --auth-local=trust --auth-host=reject >/dev/null 2>&1', $out, $code);
if ($code !== 0) {
    throw new RuntimeException('initdb failed');
}
$log = $workBase.'/pg.log';
exec(escapeshellarg($pgBin.'/pg_ctl').' -D '.escapeshellarg($dbDir).' -l '.escapeshellarg($log).' -o '.escapeshellarg("-k '{$sockDir}' -c listen_addresses='' -c shared_buffers=64MB").' -w start >/dev/null 2>&1', $out, $code);
if ($code !== 0) {
    throw new RuntimeException('pg_ctl start failed: '.(string) @file_get_contents($log));
}
$pgStarted = true;

$dsnAdmin = "pgsql:host={$sockDir};port=5432;dbname=postgres";
$adminPdo = new PDO($dsnAdmin, $pgUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec("CREATE DATABASE {$dbName}");

// Migrate + install Vaulter RLS policies + grant vaulter_runtime privileges.
$setupApp = bootVaulterApp($sockDir, $dbName, $pgUser, '', $storageDir, false, null);
$exitMigrate = Artisan::call('migrate', ['--force' => true]);
if ($exitMigrate !== 0) {
    throw new RuntimeException("migrate failed with exit {$exitMigrate}");
}
$exitRls = Artisan::call('vaulter:rls:install', ['--roles' => true]);
if ($exitRls !== 0) {
    throw new RuntimeException("vaulter:rls:install failed with exit {$exitRls}");
}

$benchPdo = new PDO("pgsql:host={$sockDir};port=5432;dbname={$dbName}", $pgUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$benchPdo->exec("ALTER ROLE {$runtimeRole} WITH PASSWORD '{$runtimePass}' LOGIN NOINHERIT NOBYPASSRLS");
$benchPdo->exec("GRANT USAGE ON SCHEMA public TO {$runtimeRole}");
$benchPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$runtimeRole}");
$benchPdo->exec("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$runtimeRole}");

// Verify RLS & NOBYPASSRLS enforcement directly in PostgreSQL catalog.
$rlsRows = $benchPdo->query("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname IN ('v_drives', 'v_nodes', 'v_node_grants') ORDER BY relname")->fetchAll(PDO::FETCH_ASSOC);
$roleRow = $benchPdo->query("SELECT rolbypassrls FROM pg_roles WHERE rolname = '{$runtimeRole}'")->fetch(PDO::FETCH_ASSOC);
if (count($rlsRows) !== 3 || ($roleRow['rolbypassrls'] ?? true) !== false) {
    throw new RuntimeException('RLS catalog check failed: '.json_encode(['tables' => $rlsRows, 'role' => $roleRow]));
}
foreach ($rlsRows as $row) {
    if (!$row['relrowsecurity'] || !$row['relforcerowsecurity']) {
        throw new RuntimeException("Table {$row['relname']} missing ENABLE/FORCE ROW LEVEL SECURITY");
    }
}

// Seed Tenant 101 and Tenant 202 Drives & Folders using Vaulter's Drive API models inside RLS transactions.
$vaulterDb = $setupApp->make(VaulterDatabase::class);
$authManager = $setupApp->make('auth');
$seedTenant = static function (int $tenantId, int $actorId, string $folderName) use ($vaulterDb, $authManager): int {
    BenchHeaderTenantResolver::$forcedTenant = $tenantId;
    try {
        return $vaulterDb->transaction(static function () use ($actorId, $folderName, $authManager): int {
            $user = (new TestUser)->forceFill(['id' => $actorId]);
            $authManager->setUser($user);
            $host = DriveTestHelpers::host($actorId);
            $drive = Vaulter::drive($host);
            $root = $drive->asSystem('seed')->root();
            AccessTestHelpers::grant(
                node: $root,
                role: 'owner',
                subjectId: $actorId,
            );
            $actor = DriveTestHelpers::actor($drive, $actorId);
            $authManager->setUser($actor);
            $drive->actingAs($actor)->folders()->create($folderName, $root);

            return (int) $drive->model()->getKey();
        });
    } finally {
        BenchHeaderTenantResolver::$forcedTenant = null;
    }
};

$driveA = $seedTenant(101, 1011, 'tenant-101-vault-alpha');
$driveB = $seedTenant(202, 2022, 'tenant-202-vault-gamma');
DB::disconnect();
unset($setupApp, $benchPdo, $adminPdo);

// Verify fail-closed DB behavior when vaulter_runtime queries without vaulter.tenant_id set.
$rtPdo = new PDO("pgsql:host={$sockDir};port=5432;dbname={$dbName}", $runtimeRole, $runtimePass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$unscopedCount = (int) $rtPdo->query('SELECT count(*) FROM v_nodes')->fetchColumn();
if ($unscopedCount !== 0) {
    throw new RuntimeException("Fail-closed RLS check failed: unscoped vaulter_runtime saw {$unscopedCount} rows");
}
unset($rtPdo);

/**
 * Forks a persistent Laravel 13 HTTP worker running as `vaulter_runtime` (NOBYPASSRLS).
 * Returns latency samples (ms), trace verification stats, and actual server worker RSS/HWM.
 */
$runWorkerPass = static function (bool $enableOtel, ?string $tracePath) use (
    $sockDir,
    $dbName,
    $runtimeRole,
    $runtimePass,
    $storageDir,
    $driveA,
    $driveB,
    $warmup,
    $requests
): array {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("Worker bind failed: {$errstr}");
    }
    $addr = stream_socket_get_name($server, false);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $workerApp = bootVaulterApp($sockDir, $dbName, $runtimeRole, $runtimePass, $storageDir, $enableOtel, $tracePath);
        /** @var HttpKernelContract $kernel */
        $kernel = $workerApp->make(HttpKernelContract::class);
        $workerRecorder = $workerApp->make(OpenTelemetryTraceRecorder::class);
        $workerPid = getmypid();

        $conn = @stream_socket_accept($server, 30);
        if ($conn !== false) {
            while (!feof($conn)) {
                $rawReq = '';
                while (!str_contains($rawReq, "\r\n\r\n")) {
                    $chunk = fread($conn, 4096);
                    if ($chunk === false || $chunk === '') {
                        break 2;
                    }
                    $rawReq .= $chunk;
                }
                $lines = explode("\r\n", trim($rawReq));
                if ($lines === [] || preg_match('#^([A-Z]+)\s+(\S+)#', $lines[0], $m) !== 1) {
                    break;
                }
                $method = $m[1];
                $uri = $m[2];
                $serverVars = ['HTTP_ACCEPT' => 'application/json'];
                foreach (array_slice($lines, 1) as $headerLine) {
                    if (!str_contains($headerLine, ':')) {
                        continue;
                    }
                    [$hName, $hVal] = explode(':', $headerLine, 2);
                    $key = 'HTTP_'.strtoupper(str_replace('-', '_', trim($hName)));
                    $serverVars[$key] = trim($hVal);
                }

                // Per-request persistent Octane state reset.
                Context::flush();
                $workerApp->forgetScopedInstances();
                $req = Request::create($uri, $method, [], [], [], $serverVars);
                $workerApp->instance('request', $req);

                $resp = $kernel->handle($req);
                $body = (string) $resp->getContent();
                $status = $resp->getStatusCode();
                $kernel->terminate($req, $resp);

                $out = "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nX-Worker-Pid: {$workerPid}\r\nConnection: keep-alive\r\n\r\n{$body}";
                fwrite($conn, $out);
            }
            fclose($conn);
        }
        if ($enableOtel) {
            $workerRecorder->forceFlush();
            $workerRecorder->shutdown();
        }
        fclose($server);
        exit(0);
    }
    fclose($server);

    $sock = @stream_socket_client("tcp://{$addr}", $errno, $errstr, 5);
    if ($sock === false) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $st);
        throw new RuntimeException("Client connect failed: {$errstr}");
    }

    try {
        // Explicit RLS isolation checks inside this booted Octane worker pass.
        $cross1 = sendHttpRequest($sock, "/api/vaulter/v1/drives/{$driveB}/nodes", ['X-Tenant-Id' => '101', 'X-Load-Actor' => '1011']);
        if ($cross1['status'] !== 404) {
            throw new RuntimeException("Tenant 101 -> Drive B ({$driveB}) expected HTTP 404, got {$cross1['status']} body={$cross1['body']}");
        }
        $cross2 = sendHttpRequest($sock, "/api/vaulter/v1/drives/{$driveA}/nodes", ['X-Tenant-Id' => '202', 'X-Load-Actor' => '2022']);
        if ($cross2['status'] !== 404) {
            throw new RuntimeException("Tenant 202 -> Drive A ({$driveA}) expected HTTP 404, got {$cross2['status']} body={$cross2['body']}");
        }
        $noTenant = sendHttpRequest($sock, "/api/vaulter/v1/drives/{$driveA}/nodes", ['X-Load-Actor' => '1011']);
        if ($noTenant['status'] < 400) {
            throw new RuntimeException("Missing tenant header expected 4xx failure, got {$noTenant['status']}");
        }

        // Warmup requests.
        for ($w = 0; $w < $warmup; $w++) {
            $isA = ($w % 2) === 0;
            $resp = sendHttpRequest(
                $sock,
                '/api/vaulter/v1/drives/'.($isA ? $driveA : $driveB).'/nodes',
                ['X-Tenant-Id' => $isA ? '101' : '202', 'X-Load-Actor' => $isA ? '1011' : '2022']
            );
            assertTenantResponse($resp, $isA ? 'tenant-101-vault-alpha' : 'tenant-202-vault-gamma', $isA ? 'tenant-202-' : 'tenant-101-', 'warmup');
        }

        $traceIds = [];
        $samples = [];
        $prefix = $enableOtel ? 'otel' : 'base';
        for ($i = 0; $i < $requests; $i++) {
            $isA = ($i % 2) === 0;
            $traceId = sprintf('%032x', $i + ($enableOtel ? 1_000_000 : 1));
            $spanId = sprintf('%016x', $i + 1);
            $traceIds[$traceId] = true;
            $headers = [
                'X-Tenant-Id' => $isA ? '101' : '202',
                'X-Load-Actor' => $isA ? '1011' : '2022',
                'traceparent' => "00-{$traceId}-{$spanId}-01",
            ];
            $t0 = hrtime(true);
            $resp = sendHttpRequest($sock, '/api/vaulter/v1/drives/'.($isA ? $driveA : $driveB).'/nodes', $headers);
            $samples[] = (hrtime(true) - $t0) / 1_000_000.0;
            assertTenantResponse(
                $resp,
                $isA ? 'tenant-101-vault-alpha' : 'tenant-202-vault-gamma',
                $isA ? 'tenant-202-' : 'tenant-101-',
                "{$prefix}#{$i}"
            );
        }

        $workerMem = readProcStatusMiB($pid);
    } finally {
        fclose($sock);
        pcntl_waitpid($pid, $st);
    }

    $totalSpans = countExportedSpans($tracePath);
    $matchedTraceSpans = countExportedSpans($tracePath, $traceIds);

    return [
        'worker_pid' => $pid,
        'stats' => stats($samples),
        'worker_rss_mib' => $workerMem['rss_mib'],
        'worker_hwm_mib' => $workerMem['hwm_mib'],
        'exported_spans_total' => $totalSpans,
        'exported_spans_matched' => $matchedTraceSpans,
    ];
};

$baselinePass = $runWorkerPass(false, $traceBaseline);
$otelPass = $runWorkerPass(true, $traceOtel);

$overheadMs = round($otelPass['stats']['p95_ms'] - $baselinePass['stats']['p95_ms'], 3);
$traceStateValid = $baselinePass['exported_spans_total'] === 0
    && $otelPass['exported_spans_total'] >= $requests
    && $otelPass['exported_spans_matched'] >= $requests;
$sloOk = $baselinePass['stats']['p95_ms'] <= 65.0
    && $otelPass['stats']['p95_ms'] <= 65.0
    && $overheadMs < 2.0
    && $traceStateValid;

$report = [
    'status' => $sloOk ? 'MEASURED_PASS' : 'MEASURED_FAIL',
    'benchmark' => 'octane_rls_and_otel_overhead',
    'mode' => 'laravel13_persistent_worker_rls_'.$mode,
    'route' => 'GET /api/vaulter/v1/drives/{drive}/nodes',
    'runtime_role' => $runtimeRole,
    'tenants_tested' => [101, 202],
    'rls_verified' => true,
    'cross_tenant_http_status' => 404,
    'warmup' => $warmup,
    'requests' => $requests,
    'baseline_no_otel' => $baselinePass,
    'with_otel' => $otelPass,
    'otel_overhead_p95_ms' => $overheadMs,
    'trace_state_valid' => $traceStateValid,
    'slo' => [
        'p95_ms_limit' => 65.0,
        'otel_p95_overhead_ms_limit' => 2.0,
    ],
];

$encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($outputFile !== null) {
    @mkdir(dirname($outputFile), 0777, true);
    file_put_contents($outputFile, $encoded);
}
echo $encoded;
exit($sloOk ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}
