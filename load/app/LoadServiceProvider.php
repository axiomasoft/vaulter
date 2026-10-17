<?php

declare(strict_types=1);

namespace Vaulter\Load;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PDO;
use Throwable;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Load\Console\ActivityWriterCommand;
use Vaulter\Load\Console\ChaosOperationCommand;
use Vaulter\Load\Console\PrepareChaosFixturesCommand;
use Vaulter\Load\Console\PrepareConcurrencyFixturesCommand;
use Vaulter\Load\Console\PrepareMemoryFixturesCommand;
use Vaulter\Load\Console\PrepareQueueFixturesCommand;
use Vaulter\Load\Console\ProbeCasStageMemoryCommand;
use Vaulter\Load\Console\QueueProbeCommand;
use Vaulter\Load\Console\SeedLoadDatasetCommand;
use Vaulter\Load\Health\LoadReadinessRunner;
use Vaulter\Load\Health\QueueReadiness;
use Vaulter\Load\Http\Controllers\ReadyController;
use Vaulter\Load\Http\Middleware\AssignLoadTier;
use Vaulter\Load\Http\Middleware\BindQueryTraceContext;
use Vaulter\Load\Http\Middleware\RecordRequestPeakMemory;
use Vaulter\Load\Listeners\FlushTraceExporter;
use Vaulter\Load\Listeners\RecordBroadcastQueueWait;
use Vaulter\Load\Listeners\RecordCommandPeakMemory;
use Vaulter\Load\Listeners\RecordFailedJob;
use Vaulter\Load\Listeners\RecordQueueLifecycle;
use Vaulter\Load\Listeners\RecordThrottledResponse;
use Vaulter\Load\Observability\CollaborationRoomMetrics;
use Vaulter\Load\Observability\DownloadStreamMetrics;
use Vaulter\Load\Observability\LoadExpositionRecorder;
use Vaulter\Load\Observability\LoadStateMetrics;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Load\Observability\QueryTraceRecorder;
use Vaulter\Load\Observability\RedisMetricsRegistry;
use Vaulter\Load\Webhooks\LoadSinkOutboundUrlGuard;
use Vaulter\Support\Config;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Load-stand host provider (P2.2). Boots ONLY under the VLT-LOAD nginx+php-fpm SUT
 * (see testbench.load.yaml) — the shipped package never loads it. Mirrors
 * packages/core/tests/E2E/E2eServiceProvider.php (P2.1 Required Reads #4), adapted for
 * the harness: full k6-exercised feature surface (operations-catalog.md), MinIO S3 disk,
 * and a `/load/storage` convenience seam that stands in for the "create storage" step —
 * vaulter has no public HTTP endpoint for that (a storage is provisioned per host entity
 * via `Vaulter::for($host)`, not POSTed), same as e2e's own `/e2e/setup`.
 */
final class LoadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $metricsEnabled = filter_var(
            env('VAULTER_LOAD_METRICS_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        );

        // Must land BEFORE VaulterServiceProvider::boot(), where routes are gated by
        // feature flags (same ordering constraint as E2eServiceProvider).
        config([
            'vaulter-drive.features.grants' => true,
            'vaulter-drive.features.activity' => true,
            'vaulter-drive.features.share_links' => true,
            'vaulter-drive.uploads.resumable.enabled' => true,
            'vaulter-documents.comments.enabled' => true,
            'vaulter-documents.collaboration.enabled' => true,

            'vaulter-drive.access.permission_driver' => 'database',
            'vaulter.ids.strategy' => env('VAULTER_LOAD_ID_STRATEGY', 'bigint'),
            'vaulter.routes.middleware.authenticated' => [
                'api',
                AssignLoadTier::class,
                LoadActingAs::class,
                BindQueryTraceContext::class,
                RecordVaulterRequestMetrics::class,
                RecordRequestPeakMemory::class,
            ],

            // MinIO — S3-compatible, path-style endpoint (MinIO does not do
            // virtual-hosted-style buckets by default).
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => env('MINIO_ROOT_USER', 'vaulter'),
                'secret' => env('MINIO_ROOT_PASSWORD', 'vaulter-load-secret'),
                'region' => env('MINIO_REGION', 'us-east-1'),
                'bucket' => env('MINIO_BUCKET', 'vaulter-load'),
                'endpoint' => env('MINIO_ENDPOINT', 'http://minio:9000'),
                'use_path_style_endpoint' => true,
                'throw' => false,
                'http' => [
                    'connect_timeout' => (float) env('LOAD_S3_CONNECT_TIMEOUT', 2),
                    // The same client carries measured multi-GiB paths. A readiness-sized
                    // transfer timeout truncates GetObject before a 5 GiB commit can read it.
                    'timeout' => (float) env('LOAD_S3_IO_TIMEOUT', 900),
                ],
            ],
            'vaulter-media.disk' => 's3',

            'load.health' => [
                's3_sentinel_key' => env('LOAD_HEALTH_S3_SENTINEL', '.vaulter-health-sentinel'),
                'db_timeout_seconds' => (float) env('LOAD_HEALTH_DB_TIMEOUT', 2),
                'queue_probe_timeout_seconds' => (float) env('LOAD_HEALTH_QUEUE_TIMEOUT', 3),
                'queue_poll_interval_us' => (int) env('LOAD_HEALTH_QUEUE_POLL_US', 50_000),
                'queue_connection' => env('LOAD_HEALTH_QUEUE_CONNECTION', 'redis'),
                'queue_name' => env('LOAD_HEALTH_QUEUE_NAME', 'load-health'),
            ],
            'load.metrics' => [
                'ttl_seconds' => (int) env('LOAD_METRICS_TTL_SECONDS', RedisMetricsRegistry::DEFAULT_TTL_SECONDS),
            ],

            'vaulter.webhooks.delivery.url_guard' => LoadSinkOutboundUrlGuard::class,

            'queue.default' => env('QUEUE_CONNECTION', 'redis'),
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => env('REDIS_QUEUE', 'default'),
                'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
                'block_for' => null,
                'after_commit' => false,
            ],

            'cache.default' => env('CACHE_STORE', 'redis'),
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'cache',
                'lock_connection' => 'default',
            ],

            'database.redis' => [
                'client' => env('REDIS_CLIENT', 'phpredis'),
                'options' => [
                    'cluster' => env('REDIS_CLUSTER', 'redis'),
                    // P8.7 registry keys are an external contract and must not receive
                    // Laravel's application-name prefix.
                    'prefix' => env('REDIS_PREFIX', ''),
                ],
                'default' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'port' => (int) env('REDIS_PORT', 6379),
                    'database' => (int) env('REDIS_DB', 0),
                    'read_timeout' => (float) env('LOAD_HEALTH_REDIS_READ_TIMEOUT', 1),
                    'timeout' => (float) env('LOAD_HEALTH_REDIS_CONNECT_TIMEOUT', 1),
                ],
                'cache' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'port' => (int) env('REDIS_PORT', 6379),
                    'database' => (int) env('REDIS_CACHE_DB', 1),
                    'read_timeout' => (float) env('LOAD_HEALTH_REDIS_READ_TIMEOUT', 1),
                    'timeout' => (float) env('LOAD_HEALTH_REDIS_CONNECT_TIMEOUT', 1),
                ],
            ],
        ]);

        $this->app->singleton(QueueReadiness::class);
        $this->app->singleton(LoadReadinessRunner::class);
        $this->app->singleton(RedisMetricsRegistry::class);
        $this->app->singleton(QueryTraceRecorder::class);

        config([
            'vaulter.observability.enabled' => $metricsEnabled,
            'vaulter.observability.recorder' => LoadExpositionRecorder::class,
        ]);

        $tracingEnabled = filter_var(env('VAULTER_TRACING_ENABLED', false), FILTER_VALIDATE_BOOL);
        $sdkReady = class_exists(TracerProvider::class);
        $sampleRatio = env('VAULTER_TRACING_SAMPLE_RATIO');
        config([
            'vaulter.observability.tracing.enabled' => $tracingEnabled && $sdkReady,
            'vaulter.observability.tracing.recorder' => ($tracingEnabled && $sdkReady)
                ? OpenTelemetryTraceRecorder::class
                : null,
            'vaulter.observability.tracing.ratio' => is_numeric($sampleRatio) ? (float) $sampleRatio : 0.01,
        ]);

        if ($tracingEnabled && $sdkReady) {
            $this->app->singleton(
                OpenTelemetryTraceRecorder::class,
                static fn (): OpenTelemetryTraceRecorder => OpenTelemetryTraceRecorder::create(),
            );
            config([
                'vaulter.routes.middleware.authenticated' => array_merge(
                    [OpenTelemetryTraceRecorder::class],
                    config('vaulter.routes.middleware.authenticated', []),
                ),
            ]);
        }

        // The collab webhook carries only the JWT `sub` (actor id) — materialise a
        // TestUser by it, same shim as E2eServiceProvider (a real host binds its own
        // HostUserResolver against its own user table).
        $this->app->bind(HostUserResolver::class, static fn (): FakeHostUserResolver => new FakeHostUserResolver);

        if ($this->app->runningUnitTests()) {
            return;
        }

        $connection = env('DB_CONNECTION', 'pgsql');
        config(['database.default' => $connection]);

        if ($connection === 'pgsql') {
            $dbTimeout = max(1, (int) round((float) env('LOAD_HEALTH_DB_TIMEOUT', 2)));

            config(['database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', 'pgbouncer'),
                'port' => env('DB_PORT', '6432'),
                'database' => env('DB_DATABASE', 'vaulter_load'),
                'username' => env('DB_USERNAME', 'vaulter'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
                'options' => [
                    PDO::ATTR_TIMEOUT => $dbTimeout,
                ],
            ]]);
        }
    }

    public function boot(): void
    {
        P5PatchWindow::register();
        config(['vaulter.webhooks.delivery.url_guard' => LoadSinkOutboundUrlGuard::class]);
        $this->app->bind(OutboundUrlGuard::class, LoadSinkOutboundUrlGuard::class);

        if (
            !$this->app->runningUnitTests()
            && filter_var(env('LOAD_DB_TRACE', false), FILTER_VALIDATE_BOOL)
        ) {
            $this->app->make(QueryTraceRecorder::class)->register();
        }

        $this->loadRoutesFrom(dirname(__DIR__).'/routes/metrics.php');

        Event::listen(JobProcessing::class, RecordBroadcastQueueWait::class);
        Event::listen(JobFailed::class, RecordFailedJob::class);
        Event::listen(JobQueueing::class, [RecordQueueLifecycle::class, 'handleQueueing']);
        Event::listen(JobQueued::class, [RecordQueueLifecycle::class, 'handleQueued']);
        Event::listen(JobProcessing::class, [RecordQueueLifecycle::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [RecordQueueLifecycle::class, 'handleProcessed']);
        Event::listen(JobExceptionOccurred::class, [RecordQueueLifecycle::class, 'handleException']);
        Event::listen(JobFailed::class, [RecordQueueLifecycle::class, 'handleFailed']);
        Event::listen(CommandFinished::class, RecordCommandPeakMemory::class);
        Event::listen(RequestHandled::class, RecordThrottledResponse::class);
        Event::listen(RequestHandled::class, FlushTraceExporter::class);
        Event::listen(JobProcessed::class, FlushTraceExporter::class);
        Event::listen(JobFailed::class, FlushTraceExporter::class);
        Event::listen(JobExceptionOccurred::class, FlushTraceExporter::class);
        Event::listen(CommandFinished::class, FlushTraceExporter::class);

        if ($this->app->bound(OpenTelemetryTraceRecorder::class)) {
            $recorder = $this->app->make(OpenTelemetryTraceRecorder::class);
            register_shutdown_function(static function () use ($recorder): void {
                $recorder->shutdown();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedLoadDatasetCommand::class,
                ActivityWriterCommand::class,
                PrepareMemoryFixturesCommand::class,
                PrepareConcurrencyFixturesCommand::class,
                PrepareQueueFixturesCommand::class,
                PrepareChaosFixturesCommand::class,
                ChaosOperationCommand::class,
                QueueProbeCommand::class,
                ProbeCasStageMemoryCommand::class,
            ]);
        }

        // Process liveness (P8.6 / D36): Laravel-native DiagnosingHealth, no DB/S3/queue probes.
        Route::get('/up', static function () {
            try {
                Event::dispatch(new DiagnosingHealth);
            } catch (Throwable $exception) {
                if (app()->hasDebugModeEnabled()) {
                    throw $exception;
                }

                report($exception);

                return response('', 500);
            }

            return response('', 200);
        });

        Route::get('/ready', ReadyController::class);

        // Legacy compose alias kept for scripts that still curl it during transition.
        Route::get('/load/health', static fn () => response()->json(['status' => 'ok']));

        Route::get('/load/metrics-snapshot', function (): JsonResponse {
            app(CollaborationRoomMetrics::class)->observeActiveRooms();
            app(LoadStateMetrics::class)->observeUploadSessions();
            app(LoadStateMetrics::class)->observeOutboxBacklog();
            app(DownloadStreamMetrics::class)->observeActiveStreams();

            return response()->json(['status' => 'ok']);
        });

        // Vaulter has no `POST storages` HTTP endpoint — a storage is provisioned per
        // host entity via `Vaulter::for($host)`, not created through the public API
        // (operations-catalog.md carries no OP-nn for it). This is the load-stand's own
        // seam for that step, same role as e2e's `/e2e/setup`: idempotent per actor id
        // (DriveTestHelpers::host($id) with a FIXED id — repeat calls resolve the SAME
        // storage/root, they do not create duplicates), grants the actor Owner on the
        // root so the immediately-following `POST storages/{id}/folders` smoke succeeds.
        Route::get('/load/storage', static function (Request $request) {
            $actorId = (int) ($request->header('X-Load-Actor') ?? 1);

            $host = DriveTestHelpers::host($actorId);
            $drive = Vaulter::for($host);
            $storage = $drive->model();
            $root = $drive->root();

            // `Vaulter::for($host)->model()` is find-or-create (idempotent per host
            // id — confirmed by repeat-calling this route against the SAME actor id
            // during P2.2 validation), but AccessTestHelpers::grant() is a plain
            // INSERT — a repeat call for the same (node, subject) trips the partial-
            // UNIQUE grant constraint. Guard explicitly so the smoke curl sequence in
            // README.md is safe to re-run.
            self::grantOwnerOnce($root, $actorId);

            return response()->json([
                'storage_id' => $storage->getKey(),
                'root_node_id' => $root->getKey(),
                'actor_id' => $actorId,
                'cas_scope' => match (Config::dedupScope()) {
                    'storage' => 's'.$storage->getKey(),
                    'tenant' => 't'.(is_numeric($storage->tenant_id) ? (int) $storage->tenant_id : 'null'),
                    default => 'global',
                },
            ]);
        });
    }

    private static function grantOwnerOnce(Node $root, int $actorId): void
    {
        $grantModel = config('vaulter-drive.models.grant');
        $subjectType = (new TestUser)->getMorphClass();

        $exists = $grantModel::query()
            ->where('node_id', $root->getKey())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $actorId)
            ->exists();

        if (!$exists) {
            AccessTestHelpers::grant($root, GrantLevel::Owner, subjectId: $actorId);
        }
    }
}
