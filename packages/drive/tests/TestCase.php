<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
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
            // Core owns the schema (v_nodes/v_drives/v_files/... + v_upload_*),
            // the Node/Storage models, Access/Sharing/Activity and Configuration\DriveConfig.
            VaulterServiceProvider::class,
            // Media binds the concrete CAS engine behind the Contracts\Cas seam —
            // Drive upload/download/versioning commit through it.
            VaulterMediaServiceProvider::class,
            // This package binds the Drive behaviour: StorageManager, the DriveHandle
            // factory, the resumable-upload lifecycle, the PurgeTrash/SweepUploads
            // commands, the drive routes and the Drive-event Activity/outbox subscribers.
            VaulterDriveServiceProvider::class,
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
