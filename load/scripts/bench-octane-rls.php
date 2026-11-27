#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Laravel 13 persistent Octane-style HTTP worker + PostgreSQL RLS + OpenTelemetry benchmark.
 *
 * Enforces storage.md §S4 & engineering-standards.md §3.8:
 *   - In `--mode=full`, requires either real Laravel Octane (`artisan octane:start` with
 *     `laravel/octane` and `frankenphp`/`roadrunner`/`swoole`) or an external live Octane
 *     HTTP stand via `--url=...` with baseline vs traced passes (`--traced-url` or
 *     `--otel-toggle-header`). Never substitutes a custom PHP socket loop in `--mode=full`.
 *   - In `--mode=smoke`, boots a clean Laravel 13 `Illuminate\Foundation\Application` HTTP
 *     kernel ONCE outside the request loop in a dedicated non-debug persistent worker subprocess
 *     (OPcache enabled, Xdebug/PCOV excluded) running as non-bypass role `vaulter_runtime`
 *     (`NOBYPASSRLS`) with `vaulter.tenancy.enabled=true` and `vaulter.tenancy.rls=true`.
 *   - Exercises `GET /api/vaulter/v1/drives/{drive}/nodes` across two tenants (101 & 202),
 *     verifying RLS isolation (positive per-tenant rows + cross-tenant 404 + missing-tenant 4xx).
 *   - Runs two independent passes (OTel off vs OTel on with W3C `traceparent`), verifying
 *     baseline exports 0 spans and traced exports cover EVERY distinct measured request `trace_id`
 *     (`distinct_trace_ids_matched === requests`, never counting duplicate spans from one request).
 *   - SLO: p95 <= 65 ms and OTel p95 overhead < 2.0 ms; exit code strictly matches MEASURED_PASS.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Load\Listeners\RecordBroadcastQueueWait;
use Vaulter\Load\Listeners\RecordCommandPeakMemory;
use Vaulter\Load\Listeners\RecordFailedJob;
use Vaulter\Load\Listeners\RecordThrottledResponse;
use Vaulter\Load\LoadServiceProvider;
use Vaulter\Load\Observability\LoadExpositionRecorder;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Observability\TelemetryMiddleware;
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

function removeDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
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

function prepareLaravelSkeleton(string $appBase): void
{
    foreach ([
        $appBase.'/bootstrap/cache',
        $appBase.'/config',
        $appBase.'/routes',
        $appBase.'/database/migrations',
        $appBase.'/storage/app',
        $appBase.'/storage/framework/cache/data',
        $appBase.'/storage/framework/sessions',
        $appBase.'/storage/framework/views',
        $appBase.'/storage/logs',
    ] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    if (!is_file($appBase.'/config/app.php')) {
        file_put_contents($appBase.'/config/app.php', "<?php\nreturn ['name' => 'VaulterBench'];\n");
    }
}

/**
 * Boots a clean Laravel 13 Application (no Orchestra Testbench kernel) configured for
 * PostgreSQL RLS (`vaulter_runtime`) and optional OpenTelemetry trace export.
 */
function bootLaravel13App(
    string $appBase,
    string $pgHost,
    string $dbName,
    string $pgUser,
    string $pgPass,
    string $storageDir,
    bool $enableOtel,
    ?string $tracePath,
    bool $forConsole = false,
): Application {
    prepareLaravelSkeleton($appBase);
    putenv('APP_ENV=local');
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');
    putenv('VAULTER_TRACING_ENABLED='.($enableOtel ? 'true' : 'false'));
    putenv('VAULTER_TRACING_SAMPLE_RATIO='.($enableOtel ? '1.0' : '0.0'));
    if ($tracePath !== null && $tracePath !== '') {
        putenv('LOAD_TRACE_EXPORT='.$tracePath);
    }

    RegisterProviders::flushState();

    $app = Application::configure(basePath: $appBase)
        ->withRouting()
        ->withMiddleware(static function (): void {})
        ->withExceptions(static function (): void {})
        ->withProviders([
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
            LoadServiceProvider::class,
        ], withBootstrapProviders: false)
        ->create();

    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    $applyConfig = static function (Application $target) use ($pgHost, $dbName, $pgUser, $pgPass, $storageDir, $enableOtel): void {
        $cfg = $target['config'];
        $cfg->set('app.env', 'local');
        $cfg->set('app.debug', false);
        $cfg->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $cfg->set('cache.default', 'array');
        $cfg->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
        $cfg->set('session.driver', 'array');
        $cfg->set('queue.default', 'sync');
        $cfg->set('queue.connections.sync', ['driver' => 'sync']);
        $cfg->set('broadcasting.default', 'null');
        $cfg->set('database.default', 'pgsql');
        $cfg->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => $pgHost,
            'port' => 5432,
            'database' => $dbName,
            'username' => $pgUser,
            'password' => $pgPass,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'disable',
        ]);
        $cfg->set('vaulter.ids.strategy', 'bigint');
        $cfg->set('vaulter.tenancy.enabled', true);
        $cfg->set('vaulter.tenancy.rls', true);
        $cfg->set('vaulter.tenancy.resolver', BenchHeaderTenantResolver::class);
        $cfg->set('vaulter-media.disk', 'local');
        $cfg->set('filesystems.default', 'local');
        $cfg->set('filesystems.disks.local', ['driver' => 'local', 'root' => $storageDir]);
        $cfg->set('vaulter.observability.enabled', false);
        $cfg->set('vaulter.observability.recorder', NullMetricsRecorder::class);
        $cfg->set('vaulter.observability.tracing.enabled', $enableOtel);
        $cfg->set('load.observability.tracing.ratio', $enableOtel ? 1.0 : 0.0);
    };

    // Apply config after LoadServiceProvider::register() sets docker defaults, before BootProviders compiles modules.
    $app->registered($applyConfig);
    $app->booting($applyConfig);

    if ($forConsole) {
        $app->make(ConsoleKernelContract::class)->bootstrap();
    } else {
        $app->make(HttpKernelContract::class)->bootstrap();
    }

    $applyConfig($app);

    $router = $app->make('router');
    $router->aliasMiddleware('bench.rls_tx', BenchRlsTransactionMiddleware::class);
    $router->prependMiddlewareToGroup('api', 'bench.rls_tx');
    $router->prependMiddlewareToGroup('api', 'vaulter.tenant');

    $app->singleton(MetricsRecorder::class, NullMetricsRecorder::class);
    $app->singleton(LoadExpositionRecorder::class, NullMetricsRecorder::class);
    if ($enableOtel) {
        $recorder = OpenTelemetryTraceRecorder::create(ratio: 1.0, exportPath: $tracePath);
        $app->instance(OpenTelemetryTraceRecorder::class, $recorder);
        $app->instance(TraceRecorder::class, $recorder);
        $router->prependMiddlewareToGroup('api', OpenTelemetryTraceRecorder::class);
    } else {
        $app->instance(TraceRecorder::class, new NullTraceRecorder);
        $app->forgetInstance(OpenTelemetryTraceRecorder::class);
    }

    foreach ([
        RecordVaulterRequestMetrics::class,
        TelemetryMiddleware::class,
        RecordThrottledResponse::class,
        RecordBroadcastQueueWait::class,
        RecordFailedJob::class,
        RecordCommandPeakMemory::class,
    ] as $singleton) {
        $app->forgetInstance($singleton);
    }

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
    $contentLength = null;
    $chunked = false;
    $connectionClose = false;
    $workerPid = 0;
    foreach (array_slice($lines, 1) as $line) {
        if (stripos($line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr($line, strlen('Content-Length:')));
        } elseif (stripos($line, 'Transfer-Encoding:') === 0 && stripos($line, 'chunked') !== false) {
            $chunked = true;
        } elseif (stripos($line, 'Connection:') === 0 && stripos($line, 'close') !== false) {
            $connectionClose = true;
        } elseif (stripos($line, 'X-Worker-Pid:') === 0) {
            $workerPid = (int) trim(substr($line, strlen('X-Worker-Pid:')));
        }
    }
    $body = $bodyStart;
    if ($chunked) {
        $decoded = '';
        $buf = $body;
        while (true) {
            while (!str_contains($buf, "\r\n")) {
                $part = fread($socket, 8192);
                if ($part === false || $part === '') {
                    throw new RuntimeException('HTTP chunked frame header truncated');
                }
                $buf .= $part;
            }
            [$sizeLine, $buf] = explode("\r\n", $buf, 2);
            $hex = trim(explode(';', $sizeLine, 2)[0]);
            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new RuntimeException("Invalid HTTP chunk size: {$sizeLine}");
            }
            $chunkSize = (int) hexdec($hex);
            if ($chunkSize === 0) {
                while (!str_contains($buf, "\r\n") && $buf !== "\r\n") {
                    $part = fread($socket, 8192);
                    if ($part === false || $part === '') {
                        break;
                    }
                    $buf .= $part;
                }
                break;
            }
            while (strlen($buf) < $chunkSize + 2) {
                $part = fread($socket, ($chunkSize + 2) - strlen($buf));
                if ($part === false || $part === '') {
                    throw new RuntimeException('HTTP chunked body truncated');
                }
                $buf .= $part;
            }
            $decoded .= substr($buf, 0, $chunkSize);
            $buf = substr($buf, $chunkSize + 2);
        }
        $body = $decoded;
    } elseif ($contentLength !== null) {
        while (strlen($body) < $contentLength) {
            $need = $contentLength - strlen($body);
            $chunk = fread($socket, $need);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('HTTP response body truncated');
            }
            $body .= $chunk;
        }
    } elseif ($connectionClose) {
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
    }

    return ['status' => $status, 'body' => $body, 'worker_pid' => $workerPid, 'connection_close' => $connectionClose];
}

/**
 * Inspect JSONL trace exports starting at $startOffsetBytes.
 *
 * Verifies distinct matched request trace_ids so multiple spans emitted by one
 * request can never mask missing traces for other measured requests.
 *
 * @param array<string, true> $expectedTraceIds
 * @return array{
 *     spans_total: int,
 *     spans_matched: int,
 *     distinct_trace_ids_matched: int,
 *     expected_trace_ids: int,
 *     missing_trace_ids_count: int,
 *     file_bytes: int
 * }
 */
function inspectExportedTraces(?string $traceFile, array $expectedTraceIds = [], int $startOffsetBytes = 0): array
{
    $expectedCount = count($expectedTraceIds);
    if ($traceFile === null || $traceFile === '' || !is_file($traceFile)) {
        return [
            'spans_total' => 0,
            'spans_matched' => 0,
            'distinct_trace_ids_matched' => 0,
            'expected_trace_ids' => $expectedCount,
            'missing_trace_ids_count' => $expectedCount,
            'file_bytes' => 0,
        ];
    }
    clearstatcache(true, $traceFile);
    $fileSize = (int) (filesize($traceFile) ?: 0);
    $offset = max(0, min($startOffsetBytes, $fileSize));
    $fp = @fopen($traceFile, 'rb');
    if ($fp === false) {
        return [
            'spans_total' => 0,
            'spans_matched' => 0,
            'distinct_trace_ids_matched' => 0,
            'expected_trace_ids' => $expectedCount,
            'missing_trace_ids_count' => $expectedCount,
            'file_bytes' => $fileSize,
        ];
    }
    try {
        if ($offset > 0) {
            fseek($fp, $offset);
        }
        $content = stream_get_contents($fp);
    } finally {
        fclose($fp);
    }
    if (!is_string($content) || $content === '') {
        return [
            'spans_total' => 0,
            'spans_matched' => 0,
            'distinct_trace_ids_matched' => 0,
            'expected_trace_ids' => $expectedCount,
            'missing_trace_ids_count' => $expectedCount,
            'file_bytes' => $fileSize,
        ];
    }
    $lines = preg_split('/\r?\n/', $content, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($lines)) {
        $lines = [];
    }
    $spansTotal = 0;
    $spansMatched = 0;
    $matchedTraceIds = [];
    foreach ($lines as $line) {
        $spansTotal++;
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $tid = isset($row['trace_id']) && is_string($row['trace_id']) ? strtolower(trim($row['trace_id'])) : '';
        if ($tid !== '' && isset($expectedTraceIds[$tid])) {
            $spansMatched++;
            $matchedTraceIds[$tid] = true;
        }
    }
    $distinctMatched = count($matchedTraceIds);

    return [
        'spans_total' => $spansTotal,
        'spans_matched' => $spansMatched,
        'distinct_trace_ids_matched' => $distinctMatched,
        'expected_trace_ids' => $expectedCount,
        'missing_trace_ids_count' => max(0, $expectedCount - $distinctMatched),
        'file_bytes' => $fileSize,
    ];
}

/**
 * Parse --otel-toggle-header (`X-Bench-Otel` or `X-Bench-Otel=offVal:onVal`).
 *
 * @return array{header: string, off: string, on: string}
 */
function parseOtelToggleHeader(string $spec): array
{
    $spec = trim($spec);
    if ($spec === '') {
        throw new RuntimeException('--otel-toggle-header cannot be empty');
    }
    $off = '0';
    $on = '1';
    $header = $spec;
    if (str_contains($spec, '=')) {
        [$header, $pair] = explode('=', $spec, 2);
        $header = trim($header);
        if (!str_contains($pair, ':')) {
            throw new RuntimeException('--otel-toggle-header with = must have format Header=offValue:onValue');
        }
        [$off, $on] = explode(':', $pair, 2);
    }
    if ($header === '' || preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
        throw new RuntimeException("Invalid header name in --otel-toggle-header: {$header}");
    }

    return ['header' => $header, 'off' => $off, 'on' => $on];
}

/** @return array{host: string, port: int, url: string} */
function validateLiveUrl(string $url, string $flag): array
{
    $url = trim($url);
    $parsed = parse_url($url);
    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    $host = (string) ($parsed['host'] ?? '');
    if ($scheme !== 'http' || $host === '') {
        throw new RuntimeException("{$flag} must be a valid http:// URL with host, got: {$url}");
    }
    $path = (string) ($parsed['path'] ?? '');
    if ($path !== '' && preg_match('#^/(load/health|health|up|ready)/?$#i', $path) === 1) {
        throw new RuntimeException("Refusing non-RLS static health endpoint in {$flag}: {$url}");
    }
    $port = (int) ($parsed['port'] ?? 80);
    if ($port <= 0 || $port > 65535) {
        throw new RuntimeException("Invalid port in {$flag}: {$url}");
    }

    return ['host' => $host, 'port' => $port, 'url' => $url];
}

/**
 * Execute one HTTP RLS benchmark pass against tcp://$host:$port, verifying
 * cross-tenant 404 isolation, missing-tenant 4xx failure, warmup, measured latencies,
 * server worker RSS/HWM, and per-request trace_id coverage.
 *
 * @param array<string, string> $extraHeaders
 */
function runHttpRlsPass(
    string $host,
    int $port,
    int $workerPid,
    string $driveA,
    string $driveB,
    string $tenantA,
    string $tenantB,
    string $actorA,
    string $actorB,
    string $needleA,
    string $needleB,
    string $forbidA,
    string $forbidB,
    int $warmup,
    int $requests,
    bool $expectTraces,
    ?string $traceFile,
    array $extraHeaders = [],
    string $label = 'pass',
): array {
    $connect = static function () use ($host, $port) {
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if ($sock === false) {
            throw new RuntimeException("Failed connecting to {$host}:{$port}: {$errstr}");
        }

        return $sock;
    };
    $sock = $connect();
    $doRequest = static function (string $path, array $headers) use (&$sock, $connect): array {
        $resp = sendHttpRequest($sock, $path, $headers);
        if ($resp['connection_close']) {
            fclose($sock);
            $sock = $connect();
        }

        return $resp;
    };

    try {
        $cross1 = $doRequest("/api/vaulter/v1/drives/{$driveB}/nodes", $extraHeaders + ['X-Tenant-Id' => $tenantA, 'X-Load-Actor' => $actorA]);
        if ($cross1['status'] !== 404) {
            throw new RuntimeException("{$label}: Tenant {$tenantA} -> Drive B ({$driveB}) expected HTTP 404, got {$cross1['status']} body={$cross1['body']}");
        }
        $cross2 = $doRequest("/api/vaulter/v1/drives/{$driveA}/nodes", $extraHeaders + ['X-Tenant-Id' => $tenantB, 'X-Load-Actor' => $actorB]);
        if ($cross2['status'] !== 404) {
            throw new RuntimeException("{$label}: Tenant {$tenantB} -> Drive A ({$driveA}) expected HTTP 404, got {$cross2['status']} body={$cross2['body']}");
        }
        $noTenant = $doRequest("/api/vaulter/v1/drives/{$driveA}/nodes", $extraHeaders + ['X-Load-Actor' => $actorA]);
        if ($noTenant['status'] < 400) {
            throw new RuntimeException("{$label}: Missing tenant header expected 4xx failure, got {$noTenant['status']}");
        }

        for ($w = 0; $w < $warmup; $w++) {
            $isA = ($w % 2) === 0;
            $resp = $doRequest(
                '/api/vaulter/v1/drives/'.($isA ? $driveA : $driveB).'/nodes',
                $extraHeaders + ['X-Tenant-Id' => $isA ? $tenantA : $tenantB, 'X-Load-Actor' => $isA ? $actorA : $actorB],
            );
            assertTenantResponse($resp, $isA ? $needleA : $needleB, $isA ? $forbidA : $forbidB, "{$label}_warmup");
        }

        clearstatcache(true, (string) $traceFile);
        $traceOffset = ($traceFile !== null && $traceFile !== '' && is_file($traceFile)) ? (int) (filesize($traceFile) ?: 0) : 0;

        $traceIds = [];
        $samples = [];
        for ($i = 0; $i < $requests; $i++) {
            $isA = ($i % 2) === 0;
            do {
                $traceId = bin2hex(random_bytes(16));
            } while (isset($traceIds[$traceId]));
            $spanId = bin2hex(random_bytes(8));
            $traceIds[$traceId] = true;
            $headers = $extraHeaders + [
                'X-Tenant-Id' => $isA ? $tenantA : $tenantB,
                'X-Load-Actor' => $isA ? $actorA : $actorB,
                'traceparent' => "00-{$traceId}-{$spanId}-01",
            ];
            $t0 = hrtime(true);
            $resp = $doRequest('/api/vaulter/v1/drives/'.($isA ? $driveA : $driveB).'/nodes', $headers);
            $samples[] = (hrtime(true) - $t0) / 1_000_000.0;
            assertTenantResponse($resp, $isA ? $needleA : $needleB, $isA ? $forbidA : $forbidB, "{$label}#{$i}");
            if ($workerPid <= 0 && $resp['worker_pid'] > 0) {
                $workerPid = $resp['worker_pid'];
            }
        }

        $workerMem = $workerPid > 0 ? readProcStatusMiB($workerPid) : ['rss_mib' => 0.0, 'hwm_mib' => 0.0];
    } finally {
        if (is_resource($sock)) {
            fclose($sock);
        }
    }

    if ($expectTraces) {
        $deadline = hrtime(true) + 500_000_000;
        do {
            $traceInfo = inspectExportedTraces($traceFile, $traceIds, $traceOffset);
            if ($traceInfo['distinct_trace_ids_matched'] >= count($traceIds)) {
                break;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
    } else {
        $traceInfo = inspectExportedTraces($traceFile, $traceIds, $traceOffset);
    }

    return [
        'worker_pid' => $workerPid,
        'stats' => stats($samples),
        'worker_rss_mib' => $workerMem['rss_mib'],
        'worker_hwm_mib' => $workerMem['hwm_mib'],
        'trace_file' => $traceFile,
        'trace_offset_bytes' => $traceOffset,
        'trace_ids' => array_keys($traceIds),
        'exported_spans_total' => $traceInfo['spans_total'],
        'exported_spans_matched' => $traceInfo['spans_matched'],
        'distinct_trace_ids_matched' => $traceInfo['distinct_trace_ids_matched'],
        'expected_trace_ids' => $traceInfo['expected_trace_ids'],
        'missing_trace_ids_count' => $traceInfo['missing_trace_ids_count'],
    ];
}

/**
 * Re-checks baseline trace_ids against the final trace-file snapshot AFTER the traced pass
 * so delayed baseline spans flushed during the traced pass fail closed.
 */
function reconcileBaselineFinalTraces(array $baselinePass, ?string $finalTraceFile): array
{
    $traceIds = [];
    foreach (($baselinePass['trace_ids'] ?? []) as $tid) {
        if (is_string($tid) && $tid !== '') {
            $traceIds[strtolower(trim($tid))] = true;
        }
    }

    $baselineFile = isset($baselinePass['trace_file']) && is_string($baselinePass['trace_file']) && $baselinePass['trace_file'] !== ''
        ? $baselinePass['trace_file']
        : null;
    $baselineOffset = max(0, (int) ($baselinePass['trace_offset_bytes'] ?? 0));

    $extraTotal = 0;
    $extraMatched = 0;
    $extraDistinct = 0;

    if ($finalTraceFile !== null && $finalTraceFile !== '' && $traceIds !== [] && is_file($finalTraceFile)) {
        // Scan the entire final snapshot from offset 0 so delayed baseline spans are caught
        // even if the trace file was replaced or truncated and regrew past $baselineOffset.
        $finalInfo = inspectExportedTraces($finalTraceFile, $traceIds, 0);
        $extraMatched = max($extraMatched, $finalInfo['spans_matched']);
        $extraDistinct = max($extraDistinct, $finalInfo['distinct_trace_ids_matched']);
        $extraTotal = max($extraTotal, $finalInfo['spans_matched']);
    }

    if ($baselineFile !== null && $baselineFile !== $finalTraceFile && is_file($baselineFile)) {
        if ($traceIds !== []) {
            $baseMatchedInfo = inspectExportedTraces($baselineFile, $traceIds, 0);
            $extraTotal = max($extraTotal, $baseMatchedInfo['spans_matched']);
            $extraMatched = max($extraMatched, $baseMatchedInfo['spans_matched']);
            $extraDistinct = max($extraDistinct, $baseMatchedInfo['distinct_trace_ids_matched']);
        }
        clearstatcache(true, $baselineFile);
        $baseSize = (int) (filesize($baselineFile) ?: 0);
        $tailOffset = $baseSize >= $baselineOffset ? $baselineOffset : 0;
        $baseTailInfo = ($tailOffset === 0 && isset($baseMatchedInfo))
            ? $baseMatchedInfo
            : inspectExportedTraces($baselineFile, $traceIds, $tailOffset);
        $extraTotal = max($extraTotal, $baseTailInfo['spans_total']);
    }

    $baselinePass['exported_spans_total'] = max((int) ($baselinePass['exported_spans_total'] ?? 0), $extraTotal);
    $baselinePass['exported_spans_matched'] = max((int) ($baselinePass['exported_spans_matched'] ?? 0), $extraMatched);
    $baselinePass['distinct_trace_ids_matched'] = max((int) ($baselinePass['distinct_trace_ids_matched'] ?? 0), $extraDistinct);

    return $baselinePass;
}

function stripInternalTraceIds(array $pass): array
{
    unset($pass['trace_ids']);

    return $pass;
}

function renderOctaneFilesystemsConfig(string $storageDir): string
{
    return "<?php\nreturn ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => ".var_export($storageDir, true)."]]];\n";
}

function evaluateOctaneRlsPasses(array $baselinePass, array $otelPass, int $requests): array
{
    $otelTraceFile = isset($otelPass['trace_file']) && is_string($otelPass['trace_file']) && $otelPass['trace_file'] !== ''
        ? $otelPass['trace_file']
        : null;
    $reconciledBaseline = reconcileBaselineFinalTraces($baselinePass, $otelTraceFile);

    $overheadMs = round($otelPass['stats']['p95_ms'] - $reconciledBaseline['stats']['p95_ms'], 3);
    $baselineTraceOk = $reconciledBaseline['exported_spans_total'] === 0
        && ($reconciledBaseline['exported_spans_matched'] ?? 0) === 0
        && $reconciledBaseline['distinct_trace_ids_matched'] === 0;
    $otelTraceOk = $otelPass['exported_spans_total'] >= $requests
        && $otelPass['distinct_trace_ids_matched'] === $requests
        && $otelPass['missing_trace_ids_count'] === 0;
    $traceStateValid = $baselineTraceOk && $otelTraceOk;
    $sloOk = $reconciledBaseline['stats']['p95_ms'] <= 65.0
        && $otelPass['stats']['p95_ms'] <= 65.0
        && $overheadMs < 2.0
        && $traceStateValid;

    return [
        'otel_overhead_p95_ms' => $overheadMs,
        'trace_state_valid' => $traceStateValid,
        'slo_ok' => $sloOk,
        'baseline_no_otel' => $reconciledBaseline,
    ];
}

/**
 * Detect whether real Laravel Octane (`laravel/octane` + FrankenPHP/RoadRunner/Swoole)
 * is installed and runnable via `php artisan octane:start`.
 *
 * @return array{server: string, binary: ?string}|null
 */
function detectOctaneServerSpec(?string $preferred = null): ?array
{
    if (!class_exists('Laravel\\Octane\\OctaneServiceProvider')) {
        return null;
    }
    $findBin = static function (string $cmd): ?string {
        $path = trim((string) @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null'));

        return $path !== '' && is_executable($path) ? $path : null;
    };
    $candidates = $preferred !== null && $preferred !== '' ? [$preferred] : ['frankenphp', 'roadrunner', 'swoole'];
    foreach ($candidates as $server) {
        if ($server === 'frankenphp' && ($bin = $findBin('frankenphp')) !== null) {
            return ['server' => 'frankenphp', 'binary' => $bin];
        }
        if ($server === 'roadrunner' && ($bin = $findBin('rr')) !== null) {
            return ['server' => 'roadrunner', 'binary' => $bin];
        }
        if ($server === 'swoole' && (extension_loaded('swoole') || extension_loaded('openswoole'))) {
            return ['server' => 'swoole', 'binary' => null];
        }
    }

    return null;
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

/**
 * Prepares a clean non-debug PHP invocation (OPcache + JIT when supported, without Xdebug/PCOV)
 * so the persistent HTTP worker process measures true Laravel 13 Octane runtime latency.
 *
 * @return array{cmd: list<string>, env: array<string, string>}
 */
function buildWorkerProcessSpec(string $workBase, array $scriptArgs): array
{
    $phpBin = PHP_BINARY;
    $env = $_ENV + $_SERVER;
    $cleanEnv = [];
    foreach ($env as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $cleanEnv[$k] = $v;
        }
    }
    $cleanEnv['XDEBUG_MODE'] = 'off';

    $iniFlags = [];
    $scanned = php_ini_scanned_files();
    $hasDebugExt = extension_loaded('xdebug') || extension_loaded('pcov');
    $hasOpcache = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
    if ($hasDebugExt && is_string($scanned) && $scanned !== '') {
        $cleanDir = $workBase.'/php-conf-'.bin2hex(random_bytes(4));
        @mkdir($cleanDir, 0775, true);
        foreach (explode(',', $scanned) as $iniPath) {
            $iniPath = trim($iniPath);
            if ($iniPath === '' || !is_file($iniPath)) {
                continue;
            }
            $base = basename($iniPath);
            if (stripos($base, 'xdebug') !== false || stripos($base, 'pcov') !== false) {
                continue;
            }
            @copy($iniPath, $cleanDir.'/'.$base);
        }
        $cleanEnv['PHP_INI_SCAN_DIR'] = $cleanDir;
    }

    if ($hasOpcache) {
        $iniFlags[] = '-d';
        $iniFlags[] = 'opcache.enable_cli=1';
        $iniFlags[] = '-d';
        $iniFlags[] = 'opcache.jit=1255';
        $iniFlags[] = '-d';
        $iniFlags[] = 'opcache.jit_buffer_size=64M';
    }

    return [
        'cmd' => array_merge([$phpBin], $iniFlags, [__FILE__, '--worker'], $scriptArgs),
        'env' => $cleanEnv,
    ];
}

function resolveMode(array $args): string
{
    if (isset($args['self-test']) || ($args['mode'] ?? null) === 'self-test') {
        return 'self-test';
    }
    if (isset($args['dry-run']) || ($args['mode'] ?? null) === 'dry-run') {
        return 'dry-run';
    }
    if (isset($args['smoke']) || ($args['mode'] ?? null) === 'smoke') {
        return 'smoke';
    }
    if (($args['mode'] ?? null) === 'full' || !isset($args['mode'])) {
        return 'full';
    }
    throw new RuntimeException('Unsupported --mode: '.(string) $args['mode']);
}

$args = parseArgs($argv);

// Lightweight mock HTTP server mode used solely by `--mode=self-test`.
if (isset($args['mock-http-server'])) {
    $traceFile = (string) ($args['trace-file'] ?? '');
    $driveA = (string) ($args['drive-a'] ?? '1');
    $driveB = (string) ($args['drive-b'] ?? '2');
    $toggleHeader = is_string($args['toggle-header'] ?? null) ? strtolower($args['toggle-header']) : '';
    $forceOtel = ($args['force-otel'] ?? '0') === '1';
    $delayUs = (int) ($args['delay-us'] ?? 0);
    $dropEverySecondTrace = ($args['drop-second-trace'] ?? '0') === '1';
    $duplicateFirstTrace = ($args['duplicate-first-trace'] ?? '0') === '1';
    $leakOnBaseline = ($args['leak-baseline'] ?? '0') === '1';
    $delayBaselineUntilTraced = ($args['delay-baseline-until-traced'] ?? '0') === '1';
    $replaceTraceOnTraced = (string) ($args['replace-trace-on-traced'] ?? '0');
    $maxRequests = (int) ($args['max-requests'] ?? 200);

    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "Mock server bind failed: {$errstr}\n");
        exit(1);
    }
    $addr = stream_socket_get_name($server, false);
    fwrite(STDOUT, json_encode(['addr' => $addr, 'pid' => getmypid()])."\n");
    fflush(STDOUT);

    $measuredIdx = 0;
    $firstTraceId = null;
    $deferredBaselineRows = [];
    $handled = 0;
    while ($handled < $maxRequests && ($conn = @stream_socket_accept($server, 15)) !== false) {
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
            $uri = $m[2];
            $hdrs = [];
            foreach (array_slice($lines, 1) as $hl) {
                if (str_contains($hl, ':')) {
                    [$hk, $hv] = explode(':', $hl, 2);
                    $hdrs[strtolower(trim($hk))] = trim($hv);
                }
            }
            $tenant = $hdrs['x-tenant-id'] ?? '';
            $traceparent = $hdrs['traceparent'] ?? '';
            $otelOn = $forceOtel;
            if ($toggleHeader !== '' && isset($hdrs[$toggleHeader])) {
                $otelOn = in_array(strtolower($hdrs[$toggleHeader]), ['1', 'true', 'on'], true);
            }
            if ($delayUs > 0 && $otelOn) {
                usleep($delayUs);
            }
            if ($tenant === '') {
                $status = 400;
                $body = json_encode(['error' => 'missing_tenant']);
            } elseif (preg_match('#^/api/vaulter/v1/drives/([^/]+)/nodes$#', $uri, $pm) !== 1) {
                $status = 404;
                $body = json_encode(['error' => 'not_found']);
            } else {
                $drive = $pm[1];
                if ($tenant === '101' && $drive === $driveA) {
                    $status = 200;
                    $body = json_encode(['data' => [['name' => 'tenant-101-vault-alpha']]]);
                } elseif ($tenant === '202' && $drive === $driveB) {
                    $status = 200;
                    $body = json_encode(['data' => [['name' => 'tenant-202-vault-gamma']]]);
                } else {
                    $status = 404;
                    $body = json_encode(['error' => 'not_found']);
                }
            }
            if ($otelOn && $deferredBaselineRows !== [] && $traceFile !== '') {
                $deferredPayload = implode('', $deferredBaselineRows);
                if ($replaceTraceOnTraced === 'truncate') {
                    file_put_contents($traceFile, $deferredPayload);
                } elseif ($replaceTraceOnTraced === '1' || $replaceTraceOnTraced === 'rename') {
                    $tmpTrace = $traceFile.'.tmp.'.getmypid();
                    file_put_contents($tmpTrace, $deferredPayload);
                    if (!@rename($tmpTrace, $traceFile)) {
                        @unlink($tmpTrace);
                        file_put_contents($traceFile, $deferredPayload);
                    }
                } else {
                    file_put_contents($traceFile, $deferredPayload, FILE_APPEND);
                }
                $deferredBaselineRows = [];
            }
            if ($status === 200 && $traceparent !== '' && preg_match('/^00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', $traceparent, $tm) === 1) {
                $tid = $tm[1];
                if ($delayBaselineUntilTraced && !$otelOn) {
                    $deferredBaselineRows[] = json_encode(['trace_id' => $tid, 'span_id' => bin2hex(random_bytes(8)), 'name' => 'vaulter.http.server'])."\n";
                } elseif ($otelOn || $leakOnBaseline) {
                    if ($firstTraceId === null) {
                        $firstTraceId = $tid;
                    }
                    $skipThis = $dropEverySecondTrace && ($measuredIdx % 2) === 1;
                    if (!$skipThis && $traceFile !== '') {
                        $row = json_encode(['trace_id' => $tid, 'span_id' => bin2hex(random_bytes(8)), 'name' => 'vaulter.http.server'])."\n";
                        file_put_contents($traceFile, $row, FILE_APPEND);
                        if ($duplicateFirstTrace && $measuredIdx === 0) {
                            file_put_contents($traceFile, $row, FILE_APPEND);
                        }
                    }
                    $measuredIdx++;
                }
            }
            $out = "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nX-Worker-Pid: ".getmypid()."\r\nConnection: keep-alive\r\n\r\n{$body}";
            fwrite($conn, $out);
            $handled++;
        }
        fclose($conn);
    }
    fclose($server);
    exit(0);
}

// Dedicated persistent Laravel 13 HTTP worker process (--worker): boots kernel ONCE outside the request loop.
if (isset($args['worker'])) {
    $appBase = (string) ($args['app-base'] ?? '');
    $pgHost = (string) ($args['pg-host'] ?? '');
    $dbName = (string) ($args['db-name'] ?? '');
    $pgUser = (string) ($args['pg-user'] ?? '');
    $pgPass = (string) ($args['pg-pass'] ?? '');
    $storageDir = (string) ($args['storage-dir'] ?? '');
    $enableOtel = ($args['otel'] ?? '0') === '1';
    $tracePath = isset($args['trace-path']) && is_string($args['trace-path']) && $args['trace-path'] !== ''
        ? $args['trace-path']
        : null;

    $workerApp = bootLaravel13App($appBase, $pgHost, $dbName, $pgUser, $pgPass, $storageDir, $enableOtel, $tracePath, false);
    /** @var HttpKernelContract $kernel */
    $kernel = $workerApp->make(HttpKernelContract::class);
    $authManager = $workerApp->make('auth');
    $workerRecorder = $enableOtel ? $workerApp->make(OpenTelemetryTraceRecorder::class) : null;
    $workerPid = getmypid();

    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "Worker bind failed: {$errstr}\n");
        exit(1);
    }
    $addr = stream_socket_get_name($server, false);
    fwrite(STDOUT, json_encode(['addr' => $addr, 'pid' => $workerPid])."\n");
    fflush(STDOUT);

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

            // Per-request persistent Octane state reset: clear tenant Context, scoped instances, request facade, and auth guards.
            Context::flush();
            $workerApp->forgetScopedInstances();
            Facade::clearResolvedInstance('request');
            if (method_exists($authManager, 'forgetGuards')) {
                $authManager->forgetGuards();
            }

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
    if ($workerRecorder !== null) {
        $workerRecorder->forceFlush();
        $workerRecorder->shutdown();
    }
    fclose($server);
    exit(0);
}

$mode = resolveMode($args);
$liveUrl = is_string($args['url'] ?? null) ? trim($args['url']) : null;
$tracedUrl = is_string($args['traced-url'] ?? null) ? trim($args['traced-url']) : null;
$otelToggleSpec = is_string($args['otel-toggle-header'] ?? null) ? trim($args['otel-toggle-header']) : null;
$octaneServerPref = is_string($args['octane-server'] ?? null) ? trim($args['octane-server']) : null;
$requests = (int) ($args['requests'] ?? ($mode === 'smoke' ? 120 : 300));
$warmup = (int) ($args['warmup'] ?? ($mode === 'smoke' ? 40 : 80));
$outputFile = is_string($args['output'] ?? null) ? $args['output'] : null;

if ($mode === 'self-test') {
    $tmpDir = sys_get_temp_dir().'/vaulter-octane-selftest-'.getmypid();
    @mkdir($tmpDir, 0775, true);
    try {
        // 1. Distinct trace_id check: 2 requests (T1, T2), 2 spans for T1 and 0 for T2 MUST fail.
        $traceCheckFile = $tmpDir.'/distinct.jsonl';
        $t1 = str_repeat('a', 32);
        $t2 = str_repeat('b', 32);
        file_put_contents($traceCheckFile, json_encode(['trace_id' => $t1, 'span_id' => '1'])."\n".json_encode(['trace_id' => $t1, 'span_id' => '2'])."\n");
        $resMissing = inspectExportedTraces($traceCheckFile, [$t1 => true, $t2 => true], 0);
        if ($resMissing['spans_matched'] !== 2 || $resMissing['distinct_trace_ids_matched'] !== 1 || $resMissing['missing_trace_ids_count'] !== 1) {
            throw new RuntimeException('distinct trace_id check failed: '.json_encode($resMissing));
        }

        // 2. --mode=full without --url must refuse custom PHP socket loop when real Octane is absent.
        if (detectOctaneServerSpec() === null) {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full 2>&1';
            exec($cmd, $outLines, $fullExit);
            if ($fullExit === 0) {
                throw new RuntimeException('--mode=full unexpectedly succeeded without real Octane or --url');
            }
        }

        // 3. --url without --traced-url or --otel-toggle-header must refuse missing baseline pass.
        $cmdNoBase = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --url=http://127.0.0.1:8080/api/vaulter/v1 --drive-a=1 --drive-b=2 --worker-pid='.getmypid().' --trace-file='.escapeshellarg($traceCheckFile).' 2>&1';
        exec($cmdNoBase, $outNoBase, $noBaseExit);
        if ($noBaseExit === 0) {
            throw new RuntimeException('--url mode succeeded without --traced-url or --otel-toggle-header');
        }

        $spawnMock = static function (array $mockFlags) use ($tmpDir): array {
            $cmd = array_merge([PHP_BINARY, __FILE__, '--mock-http-server'], $mockFlags);
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmpDir);
            if (!is_resource($proc)) {
                throw new RuntimeException('Failed spawning mock HTTP server');
            }
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $ready = json_decode(trim((string) fgets($pipes[1])), true);
            if (!is_array($ready) || !is_string($ready['addr'] ?? null) || !is_int($ready['pid'] ?? null)) {
                throw new RuntimeException('Mock HTTP server failed to start');
            }

            return ['proc' => $proc, 'pipes' => $pipes, 'url' => 'http://'.$ready['addr'], 'pid' => $ready['pid']];
        };
        $stopMock = static function (array $srv): void {
            @proc_terminate($srv['proc']);
            @fclose($srv['pipes'][1]);
            @fclose($srv['pipes'][2]);
            @proc_close($srv['proc']);
        };

        // 4. --url + --traced-url pass, missing-trace failure, and >2ms overhead failure.
        $tracedFile = $tmpDir.'/traced-pair.jsonl';
        $baseSrv = $spawnMock(['--force-otel=0']);
        $tracedSrv = $spawnMock(['--force-otel=1', '--trace-file='.$tracedFile]);
        try {
            $cmdOk = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=6 --warmup=2'
                .' --url='.escapeshellarg($baseSrv['url']).' --traced-url='.escapeshellarg($tracedSrv['url'])
                .' --drive-a=1 --drive-b=2 --worker-pid='.$baseSrv['pid'].' --traced-worker-pid='.$tracedSrv['pid']
                .' --trace-file='.escapeshellarg($tracedFile);
            exec($cmdOk.' 2>&1', $okOut, $okCode);
            if ($okCode !== 0) {
                throw new RuntimeException('Mock --url + --traced-url pass failed: '.implode("\n", $okOut));
            }
        } finally {
            $stopMock($baseSrv);
            $stopMock($tracedSrv);
        }

        // Missing trace for 2nd request even though total spans == requests (2 spans for req #0, 0 for req #1).
        $dropFile = $tmpDir.'/traced-drop.jsonl';
        $baseSrv2 = $spawnMock(['--force-otel=0']);
        $dropSrv = $spawnMock(['--force-otel=1', '--trace-file='.$dropFile, '--drop-second-trace=1', '--duplicate-first-trace=1']);
        try {
            $cmdDrop = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=2 --warmup=0'
                .' --url='.escapeshellarg($baseSrv2['url']).' --traced-url='.escapeshellarg($dropSrv['url'])
                .' --drive-a=1 --drive-b=2 --worker-pid='.$baseSrv2['pid'].' --traced-worker-pid='.$dropSrv['pid']
                .' --trace-file='.escapeshellarg($dropFile);
            exec($cmdDrop.' 2>&1', $dropOut, $dropCode);
            if ($dropCode === 0) {
                throw new RuntimeException('Mock missing trace_id case passed despite missing second trace_id');
            }
        } finally {
            $stopMock($baseSrv2);
            $stopMock($dropSrv);
        }

        // > 2ms overhead failure.
        $slowFile = $tmpDir.'/traced-slow.jsonl';
        $baseSrv3 = $spawnMock(['--force-otel=0']);
        $slowSrv = $spawnMock(['--force-otel=1', '--trace-file='.$slowFile, '--delay-us=3500']);
        try {
            $cmdSlow = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=4 --warmup=1'
                .' --url='.escapeshellarg($baseSrv3['url']).' --traced-url='.escapeshellarg($slowSrv['url'])
                .' --drive-a=1 --drive-b=2 --worker-pid='.$baseSrv3['pid'].' --traced-worker-pid='.$slowSrv['pid']
                .' --trace-file='.escapeshellarg($slowFile);
            exec($cmdSlow.' 2>&1', $slowOut, $slowCode);
            if ($slowCode === 0) {
                throw new RuntimeException('Mock >2ms OTel overhead case passed unexpectedly');
            }
        } finally {
            $stopMock($baseSrv3);
            $stopMock($slowSrv);
        }

        // 5. --url + --otel-toggle-header pass and baseline-leak fail.
        $toggleFile = $tmpDir.'/toggle.jsonl';
        $toggleSrv = $spawnMock(['--toggle-header=X-Bench-Otel', '--trace-file='.$toggleFile]);
        try {
            $cmdToggle = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=6 --warmup=2'
                .' --url='.escapeshellarg($toggleSrv['url']).' --otel-toggle-header=X-Bench-Otel'
                .' --drive-a=1 --drive-b=2 --worker-pid='.$toggleSrv['pid']
                .' --trace-file='.escapeshellarg($toggleFile);
            exec($cmdToggle.' 2>&1', $toggleOut, $toggleCode);
            if ($toggleCode !== 0) {
                throw new RuntimeException('Mock --otel-toggle-header pass failed: '.implode("\n", $toggleOut));
            }
        } finally {
            $stopMock($toggleSrv);
        }

        $leakFile = $tmpDir.'/toggle-leak.jsonl';
        $leakSrv = $spawnMock(['--toggle-header=X-Bench-Otel', '--trace-file='.$leakFile, '--leak-baseline=1']);
        try {
            $cmdLeak = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=4 --warmup=1'
                .' --url='.escapeshellarg($leakSrv['url']).' --otel-toggle-header=X-Bench-Otel'
                .' --drive-a=1 --drive-b=2 --worker-pid='.$leakSrv['pid']
                .' --trace-file='.escapeshellarg($leakFile);
            exec($cmdLeak.' 2>&1', $leakOut, $leakCode);
            if ($leakCode === 0) {
                throw new RuntimeException('Mock baseline-leak case passed unexpectedly');
            }
        } finally {
            $stopMock($leakSrv);
        }

        // 6. Delayed baseline spans flushed during the traced pass must be caught via final --trace-file snapshot.
        $delayedFile = $tmpDir.'/toggle-delayed-leak.jsonl';
        $delayedSrv = $spawnMock(['--toggle-header=X-Bench-Otel', '--trace-file='.$delayedFile, '--delay-baseline-until-traced=1']);
        try {
            $cmdDelayed = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=4 --warmup=1'
                .' --url='.escapeshellarg($delayedSrv['url']).' --otel-toggle-header=X-Bench-Otel'
                .' --drive-a=1 --drive-b=2 --worker-pid='.$delayedSrv['pid']
                .' --trace-file='.escapeshellarg($delayedFile);
            exec($cmdDelayed.' 2>&1', $delayedOut, $delayedCode);
            if ($delayedCode === 0) {
                throw new RuntimeException('Delayed baseline-leak case passed unexpectedly');
            }
            $delayedReport = json_decode(implode("\n", $delayedOut), true);
            if (
                !is_array($delayedReport)
                || ($delayedReport['status'] ?? null) !== 'MEASURED_FAIL'
                || ($delayedReport['trace_state_valid'] ?? true) !== false
                || (int) ($delayedReport['baseline_no_otel']['distinct_trace_ids_matched'] ?? 0) !== 4
            ) {
                throw new RuntimeException('Delayed baseline-leak report did not fail closed as expected: '.implode("\n", $delayedOut));
            }
        } finally {
            $stopMock($delayedSrv);
        }

        // 7. Delayed baseline spans flushed after trace-file replacement or truncation at the same path
        //    (when the regrown file exceeds the saved baseline offset) must still fail closed.
        $preseedRow = json_encode(['trace_id' => str_repeat('0', 32), 'span_id' => str_repeat('1', 16), 'name' => 'preseed'])."\n";
        $preseedPayload = str_repeat($preseedRow, 7);
        $preseedBytes = strlen($preseedPayload);
        foreach (['rename', 'truncate'] as $replaceMode) {
            $replacedFile = $tmpDir.'/toggle-replaced-'.$replaceMode.'.jsonl';
            file_put_contents($replacedFile, $preseedPayload);
            $replacedSrv = $spawnMock([
                '--toggle-header=X-Bench-Otel',
                '--trace-file='.$replacedFile,
                '--delay-baseline-until-traced=1',
                '--replace-trace-on-traced='.$replaceMode,
            ]);
            try {
                $replacedOut = [];
                $replacedCode = 0;
                $cmdReplaced = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --mode=full --requests=4 --warmup=1'
                    .' --url='.escapeshellarg($replacedSrv['url']).' --otel-toggle-header=X-Bench-Otel'
                    .' --drive-a=1 --drive-b=2 --worker-pid='.$replacedSrv['pid']
                    .' --trace-file='.escapeshellarg($replacedFile);
                exec($cmdReplaced.' 2>&1', $replacedOut, $replacedCode);
                if ($replacedCode === 0) {
                    throw new RuntimeException("Replaced/truncated ({$replaceMode}) delayed baseline-leak case passed unexpectedly");
                }
                $replacedReport = json_decode(implode("\n", $replacedOut), true);
                clearstatcache(true, $replacedFile);
                $finalBytes = is_file($replacedFile) ? (int) (filesize($replacedFile) ?: 0) : 0;
                if (
                    !is_array($replacedReport)
                    || ($replacedReport['status'] ?? null) !== 'MEASURED_FAIL'
                    || ($replacedReport['trace_state_valid'] ?? true) !== false
                    || (int) ($replacedReport['baseline_no_otel']['trace_offset_bytes'] ?? -1) !== $preseedBytes
                    || $finalBytes < $preseedBytes
                    || (int) ($replacedReport['baseline_no_otel']['distinct_trace_ids_matched'] ?? 0) !== 4
                    || (int) ($replacedReport['baseline_no_otel']['exported_spans_matched'] ?? 0) !== 4
                    || (int) ($replacedReport['baseline_no_otel']['exported_spans_total'] ?? 0) < 4
                ) {
                    throw new RuntimeException("Replaced/truncated ({$replaceMode}) trace-file regression check failed (finalBytes={$finalBytes}, preseedBytes={$preseedBytes}): ".implode("\n", $replacedOut));
                }
            } finally {
                $stopMock($replacedSrv);
            }
        }

        // 8. Clean replaced trace file at the same path without baseline leaks must still pass,
        //    and a replaced separate --baseline-trace-file with a baseline span at offset 0 must fail closed.
        $cleanReplacedFile = $tmpDir.'/clean-replaced.jsonl';
        $baseTid1 = str_repeat('c', 32);
        $baseTid2 = str_repeat('d', 32);
        $otelTid1 = str_repeat('e', 32);
        $otelTid2 = str_repeat('f', 32);
        file_put_contents(
            $cleanReplacedFile,
            json_encode(['trace_id' => $otelTid1, 'span_id' => '1111111111111111'])."\n"
            .json_encode(['trace_id' => $otelTid2, 'span_id' => '2222222222222222'])."\n"
            .str_repeat($preseedRow, 6),
        );
        $cleanEval = evaluateOctaneRlsPasses(
            [
                'stats' => ['p95_ms' => 10.0],
                'trace_file' => $cleanReplacedFile,
                'trace_offset_bytes' => $preseedBytes,
                'trace_ids' => [$baseTid1, $baseTid2],
                'exported_spans_total' => 0,
                'exported_spans_matched' => 0,
                'distinct_trace_ids_matched' => 0,
            ],
            [
                'stats' => ['p95_ms' => 10.5],
                'trace_file' => $cleanReplacedFile,
                'exported_spans_total' => 2,
                'distinct_trace_ids_matched' => 2,
                'missing_trace_ids_count' => 0,
            ],
            2,
        );
        if (!$cleanEval['trace_state_valid'] || !$cleanEval['slo_ok']) {
            throw new RuntimeException('Clean replaced trace file caused unexpected failure: '.json_encode($cleanEval));
        }

        $sepBaseFile = $tmpDir.'/separate-replaced-base.jsonl';
        file_put_contents(
            $sepBaseFile,
            json_encode(['trace_id' => $baseTid1, 'span_id' => '3333333333333333'])."\n"
            .str_repeat($preseedRow, 7),
        );
        $sepEval = evaluateOctaneRlsPasses(
            [
                'stats' => ['p95_ms' => 10.0],
                'trace_file' => $sepBaseFile,
                'trace_offset_bytes' => 500,
                'trace_ids' => [$baseTid1, $baseTid2],
                'exported_spans_total' => 0,
                'exported_spans_matched' => 0,
                'distinct_trace_ids_matched' => 0,
            ],
            [
                'stats' => ['p95_ms' => 10.5],
                'trace_file' => $cleanReplacedFile,
                'exported_spans_total' => 2,
                'distinct_trace_ids_matched' => 2,
                'missing_trace_ids_count' => 0,
            ],
            2,
        );
        if (
            $sepEval['trace_state_valid']
            || $sepEval['slo_ok']
            || (int) ($sepEval['baseline_no_otel']['distinct_trace_ids_matched'] ?? 0) !== 1
        ) {
            throw new RuntimeException('Replaced separate baseline trace file did not fail closed: '.json_encode($sepEval));
        }

        // 9. Generated filesystems.php must end with a real newline and pass `php -l`.
        $generatedFsPath = $tmpDir.'/filesystems.php';
        $generatedFs = renderOctaneFilesystemsConfig($tmpDir.'/storage');
        if (!str_ends_with($generatedFs, "\n") || str_ends_with($generatedFs, '\\n')) {
            throw new RuntimeException('Generated filesystems.php does not end with a real newline');
        }
        file_put_contents($generatedFsPath, $generatedFs);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($generatedFsPath).' 2>&1', $lintOut, $lintCode);
        if ($lintCode !== 0) {
            throw new RuntimeException('php -l failed on generated filesystems.php: '.implode("\n", $lintOut));
        }

        echo json_encode(['status' => 'SELF_TEST_PASS', 'checks' => 13], JSON_PRETTY_PRINT)."\n";
        exit(0);
    } finally {
        removeDirRecursive($tmpDir);
    }
}

if ($mode === 'dry-run') {
    $octaneRuntime = detectOctaneServerSpec($octaneServerPref);
    $out = [
        'status' => 'DRY_RUN',
        'benchmark' => 'octane_rls_and_otel_overhead',
        'mode' => 'dry-run',
        'requests' => $requests,
        'warmup' => $warmup,
        'route' => 'GET /api/vaulter/v1/drives/{drive}/nodes',
        'runtime_role' => 'vaulter_runtime (NOBYPASSRLS + FORCE ROW LEVEL SECURITY)',
        'slo' => ['p95_ms_max' => 65.0, 'otel_p95_overhead_ms_max' => 2.0],
        'full_requires_real_octane_or_url' => true,
        'real_octane_detected' => $octaneRuntime,
        'url_override' => $liveUrl,
        'traced_url_override' => $tracedUrl,
        'otel_toggle_header' => $otelToggleSpec,
        'url_requires_baseline_and_traced_pass' => true,
        'url_requires_trace_file' => true,
        'trace_verification' => 'distinct_trace_ids_matched === requests',
    ];
    $encoded = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if ($outputFile !== null) {
        @mkdir(dirname($outputFile), 0777, true);
        file_put_contents($outputFile, $encoded);
    }
    echo $encoded;
    exit(0);
}

// Live URL mode against external Octane HTTP stand (--url=... + (--traced-url=... | --otel-toggle-header=...) --drive-a=... --drive-b=... --worker-pid=... --trace-file=...).
if ($liveUrl !== null && $liveUrl !== '') {
    try {
        $baseTarget = validateLiveUrl($liveUrl, '--url');
        $tracedTarget = ($tracedUrl !== null && $tracedUrl !== '') ? validateLiveUrl($tracedUrl, '--traced-url') : null;
        $toggle = ($otelToggleSpec !== null && $otelToggleSpec !== '') ? parseOtelToggleHeader($otelToggleSpec) : null;
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }
    if ($tracedTarget === null && $toggle === null) {
        fwrite(STDERR, "--url mode requires both baseline and traced passes to measure OTel overhead (< 2 ms): provide --traced-url=<url> or --otel-toggle-header=<Header>.\n");
        exit(1);
    }
    $driveA = (string) ($args['drive-a'] ?? '');
    $driveB = (string) ($args['drive-b'] ?? '');
    $workerPid = (int) ($args['worker-pid'] ?? 0);
    $tracedWorkerPid = (int) ($args['traced-worker-pid'] ?? $workerPid);
    $traceFile = is_string($args['trace-file'] ?? null) ? trim($args['trace-file']) : '';
    $baselineTraceFile = is_string($args['baseline-trace-file'] ?? null) && trim($args['baseline-trace-file']) !== ''
        ? trim($args['baseline-trace-file'])
        : $traceFile;
    if ($driveA === '' || $driveB === '' || $workerPid <= 0 || !is_file("/proc/{$workerPid}/status") || $traceFile === '') {
        fwrite(STDERR, "--url mode requires --drive-a, --drive-b, live --worker-pid=/proc/<pid>, and --trace-file=<path> for trace verification.\n");
        exit(1);
    }
    if ($tracedWorkerPid <= 0 || !is_file("/proc/{$tracedWorkerPid}/status")) {
        fwrite(STDERR, "--traced-worker-pid=/proc/<pid> must point to a live process.\n");
        exit(1);
    }
    $tenantA = (string) ($args['tenant-a'] ?? '101');
    $tenantB = (string) ($args['tenant-b'] ?? '202');
    $actorA = (string) ($args['actor-a'] ?? '1011');
    $actorB = (string) ($args['actor-b'] ?? '2022');
    $needleA = (string) ($args['needle-a'] ?? 'tenant-101-vault-alpha');
    $needleB = (string) ($args['needle-b'] ?? 'tenant-202-vault-gamma');
    $forbidA = (string) ($args['forbid-a'] ?? 'tenant-202-');
    $forbidB = (string) ($args['forbid-b'] ?? 'tenant-101-');

    $baseHeaders = $toggle !== null ? [$toggle['header'] => $toggle['off']] : [];
    $tracedHeaders = $toggle !== null ? [$toggle['header'] => $toggle['on']] : [];
    $tracedEndpoint = $tracedTarget ?? $baseTarget;

    $baselinePass = runHttpRlsPass(
        $baseTarget['host'],
        $baseTarget['port'],
        $workerPid,
        $driveA,
        $driveB,
        $tenantA,
        $tenantB,
        $actorA,
        $actorB,
        $needleA,
        $needleB,
        $forbidA,
        $forbidB,
        $warmup,
        $requests,
        false,
        $baselineTraceFile,
        $baseHeaders,
        'live_baseline',
    );
    $otelPass = runHttpRlsPass(
        $tracedEndpoint['host'],
        $tracedEndpoint['port'],
        $tracedWorkerPid,
        $driveA,
        $driveB,
        $tenantA,
        $tenantB,
        $actorA,
        $actorB,
        $needleA,
        $needleB,
        $forbidA,
        $forbidB,
        $warmup,
        $requests,
        true,
        $traceFile,
        $tracedHeaders,
        'live_otel',
    );

    $baselinePass = reconcileBaselineFinalTraces($baselinePass, $traceFile);
    $eval = evaluateOctaneRlsPasses($baselinePass, $otelPass, $requests);
    $baselinePass = $eval['baseline_no_otel'];
    $pass = $eval['slo_ok'] && is_file($traceFile);
    $report = [
        'status' => $pass ? 'MEASURED_PASS' : 'MEASURED_FAIL',
        'benchmark' => 'octane_rls_and_otel_overhead',
        'mode' => 'live_http_rls',
        'url' => $baseTarget['url'],
        'traced_url' => $tracedEndpoint['url'],
        'otel_toggle_header' => $toggle !== null ? $toggle['header'] : null,
        'requests' => $requests,
        'warmup' => $warmup,
        'baseline_no_otel' => stripInternalTraceIds($baselinePass),
        'with_otel' => stripInternalTraceIds($otelPass),
        'otel_overhead_p95_ms' => $eval['otel_overhead_p95_ms'],
        'trace_state_valid' => $eval['trace_state_valid'],
        'slo' => ['p95_ms_limit' => 65.0, 'otel_p95_overhead_ms_limit' => 2.0],
    ];
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if ($outputFile !== null) {
        @mkdir(dirname($outputFile), 0777, true);
        file_put_contents($outputFile, $encoded);
    }
    echo $encoded;
    exit($pass ? 0 : 1);
}

$octaneSpec = $mode === 'full' ? detectOctaneServerSpec($octaneServerPref) : null;
if ($mode === 'full' && $octaneSpec === null) {
    fwrite(STDERR, "--mode=full requires real Laravel Octane (`artisan octane:start` with `laravel/octane` + frankenphp/roadrunner/swoole) or an external live Octane stand (`--url=...` with `--traced-url=...` or `--otel-toggle-header=...`). Refusing custom PHP socket loop in full mode; use --mode=smoke for local smoke.\n");
    exit(1);
}

// Self-contained Laravel 13 persistent worker + real PG18 RLS (`vaulter_runtime` NOBYPASSRLS) benchmark.
try {
    $pgBin = findPgBin();
    $workBase = sys_get_temp_dir().'/vaulter-octane-rls-'.getmypid();
    $sockDir = strlen($workBase) > 72 ? sys_get_temp_dir().'/vpgrls-'.getmypid() : $workBase;
    if (strlen($sockDir) > 72) {
        $sockDir = '/tmp/vpgrls-'.getmypid();
    }
    $appBase = $workBase.'/app';
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

    $cleanup = static function () use ($pgBin, $dbDir, $workBase, $sockDir, &$pgStarted): void {
        if ($pgStarted && is_file($dbDir.'/postmaster.pid')) {
            exec(escapeshellarg($pgBin.'/pg_ctl').' -D '.escapeshellarg($dbDir).' -m fast -w stop >/dev/null 2>&1');
        }
        removeDirRecursive($workBase);
        if ($sockDir !== $workBase) {
            removeDirRecursive($sockDir);
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

    // Migrate + install Vaulter RLS policies + grant vaulter_runtime privileges using clean Laravel 13 app.
    $setupApp = bootLaravel13App($appBase.'-setup', $sockDir, $dbName, $pgUser, '', $storageDir, false, null, forConsole: true);
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
     * Spawns an isolated persistent Laravel 13 HTTP worker running as `vaulter_runtime` (NOBYPASSRLS),
     * or real `artisan octane:start` when in `--mode=full` with `laravel/octane` + runtime available.
     * Returns latency samples (ms), distinct trace_id verification stats, and actual worker RSS/HWM.
     */
    $runWorkerPass = static function (bool $enableOtel, ?string $tracePath) use (
        $mode,
        $octaneSpec,
        $workBase,
        $appBase,
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
        if ($mode === 'full' && $octaneSpec !== null) {
            $octaneBase = $appBase.'-octane-'.($enableOtel ? 'otel' : 'base');
            prepareLaravelSkeleton($octaneBase);
            $vendorAutoload = dirname(__DIR__, 2).'/vendor/autoload.php';
            $supportPhp = <<<'PHP'
<?php
declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Load\Observability\LoadExpositionRecorder;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;

final class OctaneBenchTenantResolver implements TenantResolver
{
    public function current(): int|string|null
    {
        if (function_exists('app') && app()->bound('request')) {
            $raw = app('request')->header('X-Tenant-Id');
            if (is_string($raw) && $raw !== '') {
                return ctype_digit($raw) ? (int) $raw : $raw;
            }
        }

        return null;
    }
}

final class OctaneBenchRlsMiddleware
{
    public function __construct(private readonly VaulterDatabase $db) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->header('X-Tenant-Id', '') === '') {
            return response()->json(['error' => 'missing_tenant_header'], 400);
        }

        $response = $this->db->transaction(static fn () => $next($request));
        if (is_object($response) && property_exists($response, 'headers')) {
            $response->headers->set('X-Worker-Pid', (string) getmypid());
        }

        return $response;
    }
}

final class OctaneBenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $enableOtel = filter_var(env('VAULTER_TRACING_ENABLED', false), FILTER_VALIDATE_BOOL);
        $tracePath = env('LOAD_TRACE_EXPORT') ?: null;
        config([
            'app.debug' => false,
            'vaulter.ids.strategy' => 'bigint',
            'vaulter.tenancy.enabled' => true,
            'vaulter.tenancy.rls' => true,
            'vaulter.tenancy.resolver' => OctaneBenchTenantResolver::class,
            'vaulter.observability.enabled' => false,
            'vaulter.observability.recorder' => NullMetricsRecorder::class,
            'vaulter.observability.tracing.enabled' => $enableOtel,
            'load.observability.tracing.ratio' => $enableOtel ? 1.0 : 0.0,
        ]);
        $this->app->singleton(MetricsRecorder::class, NullMetricsRecorder::class);
        $this->app->singleton(LoadExpositionRecorder::class, NullMetricsRecorder::class);
        if ($enableOtel) {
            $recorder = OpenTelemetryTraceRecorder::create(ratio: 1.0, exportPath: is_string($tracePath) ? $tracePath : null);
            $this->app->instance(OpenTelemetryTraceRecorder::class, $recorder);
            $this->app->instance(TraceRecorder::class, $recorder);
        } else {
            $this->app->instance(TraceRecorder::class, new NullTraceRecorder);
        }
    }

    public function boot(): void
    {
        $router = $this->app->make('router');
        $router->aliasMiddleware('bench.rls_tx', OctaneBenchRlsMiddleware::class);
        $router->prependMiddlewareToGroup('api', 'bench.rls_tx');
        $router->prependMiddlewareToGroup('api', 'vaulter.tenant');
        if (filter_var(env('VAULTER_TRACING_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            $router->prependMiddlewareToGroup('api', OpenTelemetryTraceRecorder::class);
        }
    }
}
PHP;
            file_put_contents($octaneBase.'/bootstrap/bench-support.php', $supportPhp);
            $providersPhp = "<?php\nreturn [\n"
                ."    Vaulter\\VaulterServiceProvider::class,\n"
                ."    Vaulter\\Media\\VaulterMediaServiceProvider::class,\n"
                ."    Vaulter\\Drive\\VaulterDriveServiceProvider::class,\n"
                ."    Vaulter\\Documents\\VaulterDocumentsServiceProvider::class,\n"
                ."    Vaulter\\Load\\LoadServiceProvider::class,\n"
                ."    Laravel\\Octane\\OctaneServiceProvider::class,\n"
                ."    OctaneBenchServiceProvider::class,\n"
                ."];\n";
            file_put_contents($octaneBase.'/bootstrap/providers.php', $providersPhp);
            $appBootstrap = "<?php\nrequire_once __DIR__.'/bench-support.php';\nuse Illuminate\\Foundation\\Application;\nreturn Application::configure(basePath: dirname(__DIR__))\n"
                ."    ->withRouting()\n    ->withMiddleware(static function (): void {})\n"
                ."    ->withExceptions(static function (): void {})\n    ->create();\n";
            file_put_contents($octaneBase.'/bootstrap/app.php', $appBootstrap);
            @mkdir($octaneBase.'/public', 0775, true);
            file_put_contents($octaneBase.'/public/index.php', "<?php\nrequire ".var_export($vendorAutoload, true).";\n\$app = require __DIR__.'/../bootstrap/app.php';\n\$app->handleRequest(Illuminate\\Http\\Request::capture());\n");
            file_put_contents($octaneBase.'/artisan', "#!/usr/bin/env php\n<?php\nrequire ".var_export($vendorAutoload, true).";\n\$app = require __DIR__.'/bootstrap/app.php';\nexit(\$app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n");
            file_put_contents($octaneBase.'/config/octane.php', "<?php\nreturn ['server' => env('OCTANE_SERVER', 'frankenphp'), 'https' => false];\n");
            file_put_contents($octaneBase.'/config/filesystems.php', renderOctaneFilesystemsConfig($storageDir));
            file_put_contents($octaneBase.'/config/database.php', "<?php\nreturn ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'host' => env('DB_HOST'), 'port' => 5432, 'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'sslmode' => 'disable']]];\n");

            $probeSock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($probeSock === false) {
                throw new RuntimeException("Unable to reserve port for Octane: {$errstr}");
            }
            $probeAddr = stream_socket_get_name($probeSock, false);
            fclose($probeSock);
            $port = (int) explode(':', $probeAddr)[1];

            $envSpec = buildWorkerProcessSpec($workBase, [])['env'];
            $envSpec['APP_ENV'] = 'local';
            $envSpec['APP_DEBUG'] = 'false';
            $envSpec['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
            $envSpec['DB_CONNECTION'] = 'pgsql';
            $envSpec['DB_HOST'] = $sockDir;
            $envSpec['DB_DATABASE'] = $dbName;
            $envSpec['DB_USERNAME'] = $runtimeRole;
            $envSpec['DB_PASSWORD'] = $runtimePass;
            $envSpec['CACHE_STORE'] = 'array';
            $envSpec['SESSION_DRIVER'] = 'array';
            $envSpec['QUEUE_CONNECTION'] = 'sync';
            $envSpec['OCTANE_SERVER'] = $octaneSpec['server'];
            $envSpec['VAULTER_TRACING_ENABLED'] = $enableOtel ? 'true' : 'false';
            $envSpec['VAULTER_TRACING_SAMPLE_RATIO'] = $enableOtel ? '1.0' : '0.0';
            if ($tracePath !== null && $tracePath !== '') {
                $envSpec['LOAD_TRACE_EXPORT'] = $tracePath;
            }

            $cmd = [PHP_BINARY, $octaneBase.'/artisan', 'octane:start', '--server='.$octaneSpec['server'], '--host=127.0.0.1', '--port='.(string) $port, '--workers=1', '--no-interaction'];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $octaneBase, $envSpec);
            if (!is_resource($proc)) {
                throw new RuntimeException('Failed to start php artisan octane:start');
            }
            fclose($pipes[0]);
            $status = proc_get_status($proc);
            $masterPid = (int) ($status['pid'] ?? 0);

            $deadline = hrtime(true) + 15_000_000_000;
            $ready = false;
            while (hrtime(true) < $deadline) {
                $testConn = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 0.2);
                if ($testConn !== false) {
                    fclose($testConn);
                    $ready = true;
                    break;
                }
                usleep(50_000);
            }
            if (!$ready) {
                proc_terminate($proc);
                $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException("artisan octane:start did not listen on 127.0.0.1:{$port}: {$out}");
            }
            try {
                return runHttpRlsPass(
                    '127.0.0.1',
                    $port,
                    0,
                    (string) $driveA,
                    (string) $driveB,
                    '101',
                    '202',
                    '1011',
                    '2022',
                    'tenant-101-vault-alpha',
                    'tenant-202-vault-gamma',
                    'tenant-202-',
                    'tenant-101-',
                    $warmup,
                    $requests,
                    $enableOtel,
                    $tracePath,
                    [],
                    'octane_'.($enableOtel ? 'otel' : 'base'),
                );
            } finally {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        }

        $spec = buildWorkerProcessSpec($workBase, [
            '--app-base='.$appBase.'-'.($enableOtel ? 'otel' : 'base'),
            '--pg-host='.$sockDir,
            '--db-name='.$dbName,
            '--pg-user='.$runtimeRole,
            '--pg-pass='.$runtimePass,
            '--storage-dir='.$storageDir,
            '--otel='.($enableOtel ? '1' : '0'),
            '--trace-path='.($tracePath ?? ''),
        ]);

        $proc = proc_open(
            $spec['cmd'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $spec['env'],
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to spawn persistent Laravel 13 HTTP worker process');
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 15);
        $readyLine = fgets($pipes[1]);
        $ready = is_string($readyLine) ? json_decode(trim($readyLine), true) : null;
        if (!is_array($ready) || !is_string($ready['addr'] ?? null) || !is_int($ready['pid'] ?? null)) {
            $errOut = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
            throw new RuntimeException('Persistent worker failed to start: '.trim((string) $readyLine).' '.$errOut);
        }
        [$host, $portStr] = explode(':', $ready['addr'], 2);
        $workerPid = $ready['pid'];

        try {
            return runHttpRlsPass(
                $host,
                (int) $portStr,
                $workerPid,
                (string) $driveA,
                (string) $driveB,
                '101',
                '202',
                '1011',
                '2022',
                'tenant-101-vault-alpha',
                'tenant-202-vault-gamma',
                'tenant-202-',
                'tenant-101-',
                $warmup,
                $requests,
                $enableOtel,
                $tracePath,
                [],
                $enableOtel ? 'otel' : 'base',
            );
        } finally {
            $errOut = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            if ($exitCode !== 0) {
                throw new RuntimeException("Persistent worker exited with {$exitCode}: {$errOut}");
            }
        }
    };

    $baselinePass = $runWorkerPass(false, $traceBaseline);
    $otelPass = $runWorkerPass(true, $traceOtel);

    $eval = evaluateOctaneRlsPasses($baselinePass, $otelPass, $requests);
    $baselinePass = $eval['baseline_no_otel'];
    $sloOk = $eval['slo_ok'];

    $report = [
        'status' => $sloOk ? 'MEASURED_PASS' : 'MEASURED_FAIL',
        'benchmark' => 'octane_rls_and_otel_overhead',
        'mode' => ($mode === 'full' && $octaneSpec !== null) ? 'laravel13_octane_'.$octaneSpec['server'] : 'laravel13_persistent_worker_'.$mode,
        'octane_runtime' => ($mode === 'full' && $octaneSpec !== null) ? 'artisan_octane_start:'.$octaneSpec['server'] : 'persistent_laravel_kernel_smoke',
        'full_requires_real_octane_or_url' => true,
        'route' => 'GET /api/vaulter/v1/drives/{drive}/nodes',
        'runtime_role' => $runtimeRole,
        'tenants_tested' => [101, 202],
        'rls_verified' => true,
        'cross_tenant_http_status' => 404,
        'warmup' => $warmup,
        'requests' => $requests,
        'baseline_no_otel' => stripInternalTraceIds($baselinePass),
        'with_otel' => stripInternalTraceIds($otelPass),
        'otel_overhead_p95_ms' => $eval['otel_overhead_p95_ms'],
        'trace_state_valid' => $eval['trace_state_valid'],
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
