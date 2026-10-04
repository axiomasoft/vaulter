<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Core owns the schema (v_nodes/v_storages/... + v_node_comments/
            // v_node_document_versions), the Node/Storage models, Access/Sharing/
            // Activity and Support\Config.
            VaulterServiceProvider::class,
            // The document-node tests build their Storage fixtures through
            // Vaulter::for() (a Drive handle) — documents requires only core in
            // production (T6), but its OWN test suite needs drive installed to
            // create those fixtures. Document content itself is never CAS-committed,
            // but DriveContext eagerly constructs ALL Drive Actions (incl.
            // UploadFileAction) on build, which needs the Contracts\Cas\BlobStore
            // seam bound — so media is a transitive test-only need too.
            VaulterDriveServiceProvider::class,
            VaulterMediaServiceProvider::class,
            // This package binds the Documents behaviour: the TipTap/Univer format
            // drivers (added to core's registry), the collaboration
            // manager/broadcast channel, the document routes and the
            // Documents-event Activity/outbox subscribers. The registry engine
            // itself and the NodePresenter seam are core-owned (P0.4/D10) —
            // VaulterServiceProvider (core) binds them, not this provider.
            VaulterDocumentsServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());

        // The package's `vaulter-public`/`vaulter-uploads` named rate limiters
        // (P1.3 / F-P1.8-01) are backed by the Laravel cache. Testbench's own
        // default cache store is 'database', which needs a `cache` table this
        // suite's migrations never create — pin the in-memory 'array' store so a
        // throttled route (the collab webhooks) never 500s on a missing table.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.middleware', []);

        // The Documents suite runs OPEN under the env-gated NullPermissionDriver (non-prod
        // only, H.2 / D6″): every actor gets full access so the tests exercise pure
        // document mechanics without an access-control lane.
        $app['config']->set('vaulter.drivers.permission', 'null');

        // Pin the auto-increment strategy to bigint — the Documents tests assert
        // integer keys on the Storage→Node chain they build.
        $app['config']->set('vaulter.ids.default', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The whole v_* schema (incl. v_node_comments/v_node_document_versions) is
        // owned by core; load its migrations through the path-repo symlink Composer
        // places in this package's vendor dir.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/axioma-studio/vaulter-core/database/migrations');
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite. Opt INTO
     * Postgres by exporting DB_CONNECTION=pgsql (plus the DB_* coordinates): the
     * dedicated pgsql CI lane exercises the yjs_state bytea NUL-safe round-trip and
     * the v_activity/v_node_document_versions jsonb gin(jsonb_path_ops) indexes that
     * SQLite cannot model.
     *
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        if (env('DB_CONNECTION') !== 'pgsql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => (string) env('DB_DATABASE', 'vaulter_test'),
            'username' => (string) env('DB_USERNAME', 'vaulter'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
