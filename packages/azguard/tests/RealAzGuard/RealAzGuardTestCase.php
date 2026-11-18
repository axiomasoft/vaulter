<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuard;

use AzGuard\AzGuardServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\AzGuardUser;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\DocumentsPanel;
use Vaulter\AzGuard\Tests\RealAzGuard\Stubs\WorkspaceModel;
use Vaulter\AzGuard\VaulterAzGuardServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

use function Orchestra\Testbench\load_migration_paths;

/**
 * The REAL-ecosystem case: boots AzGuard v1 (its storage migration, the engine and the
 * host's {@see DocumentsPanel}), vaulter-core and this bridge — with the AzGuard
 * permission driver selected. A test here drives the whole chain end-to-end against a
 * REAL Eloquent AzGuard subject (stored grants, assignment scopes, the super-admin
 * role), not a double.
 */
abstract class RealAzGuardTestCase extends Orchestra
{
    /**
     * @param  Application        $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AzGuardServiceProvider::class,
            DocumentsPanel::class,
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterAzGuardServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter-drive.access.permission_driver', 'azguard');

        // AzGuard identities are type aliases, never class names: the host maps its
        // subject and workspace models.
        Relation::morphMap([
            'user' => AzGuardUser::class,
            'project' => WorkspaceModel::class,
        ]);
    }

    /**
     * AzGuard loads its own storage migration through its provider; here we add the host
     * tables the stubs live in (the User subject + a workspace entity to scope against).
     * Registered as a migration path so RefreshDatabase creates them once with the rest of
     * the schema: creating them in setUp outlived the first test on a server engine
     * ("relation users already exists").
     */
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/database/migrations');
    }
}
