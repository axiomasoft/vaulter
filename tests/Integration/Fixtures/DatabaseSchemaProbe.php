<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Fixtures;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionServiceProvider;
use Vaulter\Integration\Tests\TestCase;

final class DatabaseSchemaProbe extends TestCase
{
    use RefreshDatabase {
        migrateDatabases as private migrateSchema;
    }

    public static int $migrations = 0;

    public string $prefix = 'v_';

    public string $strategy = 'bigint';

    public bool $permissions = false;

    public function exercise(Closure $assert): void
    {
        try {
            $this->setUp();
            $assert();
        } finally {
            $this->tearDown();
        }
    }

    protected function migrateDatabases(): void
    {
        self::$migrations++;
        $this->migrateSchema();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.database.table_prefix', $this->prefix);
        $app['config']->set('vaulter.ids.strategy', $this->strategy);
        $app['config']->set('permission.teams', true);
        $app['config']->set('permission.testing', true);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...($this->permissions ? [PermissionServiceProvider::class] : []),
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if ($this->permissions) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
