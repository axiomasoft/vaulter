<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests;

use Illuminate\Foundation\Application;
use Override;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Boots the package alongside spatie/laravel-permission with the v8 TEAMS feature
 * enabled, so the SpatiePermissionDriver is exercised against a real spatie schema
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
    #[Override]
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
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Drive ACL runs the spatie lane in these tests.
        $app['config']->set('vaulter-drive.access.permission_driver', 'spatie');

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

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // Register the users + spatie permission tables as MIGRATIONS (see
        // tests/database/migrations) rather than creating them by hand here. That puts
        // them on RefreshDatabase's one-time migrate:fresh path — committed once and
        // durable across the per-test transaction on a PERSISTENT connection (the pgsql
        // lane), exactly like the package's own migrations. Hand-run DDL in this hook
        // does not survive that transaction and re-runs into "relation already exists".
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
