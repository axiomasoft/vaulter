<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Boots the package alongside spatie/laravel-permission with the v8 TEAMS feature
 * enabled, so the SpatiePermissionResolver is exercised against a real spatie schema
 * (permissions, roles, model pivots — all team-scoped) and a real HasRoles user. The
 * spatie migration stub is run by hand (it is a .php.stub, not a loadable migration),
 * and a minimal `users` table backs the actor.
 *
 * spatie/laravel-permission is a require-dev dependency only; this TestCase is the
 * one place that wires it, keeping the rest of the suite spatie-free.
 */
class SpatieTestCase extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            PermissionServiceProvider::class,
        ];
    }

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Drive ACL runs the spatie lane in these tests.
        $app['config']->set('vaulter.drivers.permission', 'spatie');

        // Enable spatie v8 teams: the migration adds team_foreign_key columns and the
        // driver runs the full setPermissionsTeamId team-switch.
        $app['config']->set('permission.teams', true);

        // sqlite needs spatie's testing fix so the team columns are created even when
        // the migration runs before teams config in some boot orders.
        $app['config']->set('permission.testing', true);

        // Pin spatie's permission cache to the array store. spatie's migration up()
        // and the driver's forgetCachedPermissions() flush config('permission.cache.store'),
        // which falls back to cache.default — that resolves to the `database` store in
        // this Testbench env (no `cache` table on the :memory: connection), so a real
        // flush throws "no such table: cache". Keeping the permission cache in-memory
        // makes these tests independent of the ambient cache driver and of suite order.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('permission.cache.store', 'array');
    }

    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createUsersTable();
        $this->runSpatieMigration();
    }

    /**
     * A minimal users table to back the HasRoles actor (the host normally owns this).
     */
    private function createUsersTable(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });
    }

    /**
     * Run spatie's create_permission_tables migration. It ships as a .php.stub
     * (so it is not auto-discovered); we require it directly — it returns an
     * anonymous Migration whose up() reads config('permission.*') at run time, so
     * teams are honoured because defineEnvironment set them first.
     */
    private function runSpatieMigration(): void
    {
        $stub = dirname(__DIR__, 3)
            .'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';

        $migration = require $stub;

        $migration->up();
    }
}
