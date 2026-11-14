<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Support\OpenApi\ValidatesOpenApiResponses;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;
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
            // registry and the document presenter, the document routes and the
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
        $this->pinSuiteConnection($app);
        $this->pinOpenSuite($app);
        $app['config']->set('vaulter-drive.access.permission_driver', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
