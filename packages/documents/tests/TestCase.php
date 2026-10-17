<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use ValidatesOpenApiResponses;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // Core owns the schema (v_nodes/v_drives/... + v_node_comments/
            // v_documents/v_document_revisions), the Node/Storage models, Access/Sharing/
            // Activity and Configuration\DriveConfig.
            VaulterServiceProvider::class,
            // The document-node tests build their Storage fixtures through
            // Vaulter::drive() (a Drive handle) — documents requires only core in
            // production (T6), but its OWN test suite needs drive installed to
            // create those fixtures. Document content itself is never CAS-committed,
            // but DriveContext eagerly constructs ALL Drive Actions (incl.
            // UploadFileAction) on build, which needs the Contracts\Cas\BlobStore
            // seam bound — so media is a transitive test-only need too.
            VaulterDriveServiceProvider::class,
            VaulterMediaServiceProvider::class,
            // This package binds the Documents behaviour: the document type
            // registry and the document presenter, the collaboration
            // manager/broadcast channel, the document routes and the
            // Documents-event Activity/outbox subscribers. The file handler
            // registry and the NodePresenter seam are core-owned (P0.4/D10) —
            // VaulterServiceProvider (core) binds them, not this provider.
            VaulterDocumentsServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->validateOpenApiResponses($app);

        $app['config']->set('vaulter.database.write_guard', (bool) env('VAULTER_TEST_WRITE_GUARD', false));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->suiteDatabaseConnection());
        SuiteDatabaseConnection::isolateStorage($app);

        // The package's `vaulter-public`/`vaulter-uploads` named rate limiters
        // (P1.3 / F-P1.8-01) are backed by the Laravel cache. Testbench's own
        // default cache store is 'database', which needs a `cache` table this
        // suite's migrations never create — pin the in-memory 'array' store so a
        // throttled route (the collab webhooks) never 500s on a missing table.
        $app['config']->set('cache.default', 'array');

        // Drop the route middleware group so tests do not depend on the host's
        // 'api' group definition; authorization is exercised via the resolver.
        $app['config']->set('vaulter.routes.middleware.authenticated', []);

        // The Documents suite runs OPEN under the env-gated NullPermissionDriver (non-prod
        // only, H.2 / D6″): every actor gets full access so the tests exercise pure
        // document mechanics without an access-control lane.
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');

        // Pin the auto-increment strategy to bigint — the Documents tests assert
        // integer keys on the Storage→Node chain they build.
        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A test meant for another engine is skipped here: the application is booted, no schema exists yet (groups engine:*).
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        // The whole v_* schema (incl. v_node_comments/v_documents/v_document_revisions) is
        // owned by core; load its migrations through the path-repo symlink Composer
        // places in this package's vendor dir.
        $this->loadMigrationsFrom(SuiteDatabaseConnection::migrationPaths($this->app));
    }

    /**
     * The connection the whole suite runs on. Default: in-memory SQLite. Opt INTO
     * Postgres by exporting DB_CONNECTION=pgsql (plus the DB_* coordinates): the
     * dedicated pgsql CI lane exercises the yjs_state bytea NUL-safe round-trip and
     * the v_activity/v_document_revisions jsonb gin(jsonb_path_ops) indexes that
     * SQLite cannot model.
     *
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        return SuiteDatabaseConnection::resolve();
    }
}
