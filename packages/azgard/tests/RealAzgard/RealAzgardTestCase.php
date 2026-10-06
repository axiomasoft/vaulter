<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard;

use AzGuard\AzGuardServiceProvider;
use AzGuard\Context\AzGuardContextServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaulter\Azgard\VaulterAzgardServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * The REAL-ecosystem case: boots azguard-core (its own migrations + grant-source chain),
 * azguard-context (the {@see ContextGuard} that answers the workspace
 * lane), vaulter-core and this bridge — with the AzGuard permission driver selected. A
 * test here drives the whole chain end-to-end against a REAL Eloquent AzGuard user
 * (real roles / scoped roles / grant sources), not a double.
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
            AzGuardContextServiceProvider::class,
            VaulterServiceProvider::class,
            VaulterAzgardServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('az-guard.cache.store', 'array');
        $app['config']->set('vaulter.access.permission_driver', 'azgard');
    }

    /**
     * azguard-core auto-loads its own tables via its provider; here we add the host
     * tables the stubs live in (the User actor + a workspace entity to scope against).
     */
    protected function defineDatabaseMigrations(): void
    {
        // azguard-context ships its context-roles table as a publishable migration
        // (not auto-loaded), so the workspace lane needs it loaded explicitly here.
        $this->loadMigrationsFrom(
            dirname(__DIR__, 2).'/vendor/axioma-studio/azguard-context/database/migrations',
        );

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
