<?php

declare(strict_types=1);

namespace Vaulter\Load;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Load\Console\ActivityWriterCommand;
use Vaulter\Load\Console\PrepareConcurrencyFixturesCommand;
use Vaulter\Load\Console\SeedLoadDatasetCommand;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;
use Vaulter\Tests\Fixtures\TestUser;

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
        // Must land BEFORE VaulterServiceProvider::boot(), where routes are gated by
        // feature flags (same ordering constraint as E2eServiceProvider).
        config([
            'vaulter.features.blobs' => true,
            'vaulter.features.versions' => true,
            'vaulter.features.grants' => true,
            'vaulter.features.activity' => true,
            'vaulter.features.uploads' => true,
            'vaulter.features.share_links' => true,
            'vaulter.features.comments' => true,
            'vaulter.features.document_versions' => true,
            'vaulter.features.collaboration' => true,

            'vaulter.drivers.permission' => 'database',
            'vaulter.ids.default' => env('VAULTER_LOAD_ID_STRATEGY', 'bigint'),
            'vaulter.middleware' => ['api', LoadActingAs::class],

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
            ],
            'vaulter.storage.default_disk' => 's3',
        ]);

        // The collab webhook carries only the JWT `sub` (actor id) — materialise a
        // TestUser by it, same shim as E2eServiceProvider (a real host binds its own
        // HostUserResolver against its own user table).
        $this->app->bind(HostUserResolver::class, static fn (): FakeHostUserResolver => new FakeHostUserResolver);

        $connection = env('DB_CONNECTION', 'pgsql');
        config(['database.default' => $connection]);

        if ($connection === 'pgsql') {
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
            ]]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedLoadDatasetCommand::class,
                ActivityWriterCommand::class,
                PrepareConcurrencyFixturesCommand::class,
            ]);
        }

        // No `/up` in the package (that seam is P8) — this is the compose healthcheck
        // target instead (Scope Included P2.2).
        Route::get('/load/health', static fn () => response()->json(['status' => 'ok']));

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
            $storage = $drive->storage();
            $root = $drive->root();

            // `Vaulter::for($host)->storage()` is find-or-create (idempotent per host
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
            ]);
        });
    }

    private static function grantOwnerOnce(Node $root, int $actorId): void
    {
        $grantModel = config('vaulter.models.grant');
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
