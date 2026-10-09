<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard;

use AzGuard\AzGuardServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\AzgardUser;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\DocumentsPanel;
use Vaulter\Azgard\Tests\RealAzgard\Stubs\WorkspaceModel;
use Vaulter\Azgard\VaulterAzgardServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The REAL-ecosystem case: boots AzGuard v1 (its storage migration, the engine and the
 * host's {@see DocumentsPanel}), vaulter-core and this bridge — with the AzGuard
 * permission driver selected. A test here drives the whole chain end-to-end against a
 * REAL Eloquent AzGuard subject (stored grants, assignment scopes, the super-admin
 * role), not a double.
 */
abstract class RealAzgardTestCase extends Orchestra
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
            VaulterAzgardServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('vaulter.access.permission_driver', 'azgard');

        // AzGuard identities are type aliases, never class names: the host maps its
        // subject and workspace models.
        Relation::morphMap([
            'user' => AzgardUser::class,
            'project' => WorkspaceModel::class,
        ]);
    }

    /**
     * AzGuard loads its own storage migration through its provider; here we add the host
     * tables the stubs live in (the User subject + a workspace entity to scope against).
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }
}
