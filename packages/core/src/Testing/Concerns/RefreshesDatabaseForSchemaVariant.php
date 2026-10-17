<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

use function Orchestra\Testbench\load_migration_paths;

use Vaulter\Attributes\Internal;

/**
 * Reuses a server schema while RefreshDatabase rolls back each test's data.
 * A change of TestCase, connection or schema configuration forces fresh migrations.
 */
#[Internal]
trait RefreshesDatabaseForSchemaVariant
{
    private static ?string $databaseSchema = null;

    protected function loadMigrationsFrom(array|string $paths): void
    {
        // SQLite retains Testbench's in-memory lifecycle. Race tests and tests
        // without RefreshDatabase retain their committed migrations and rollback.
        if (!static::usesRefreshDatabaseTestingConcern()
            || $this->usesSqliteInMemoryDatabaseConnection()
            || (is_array($paths) && !array_is_list($paths))) {
            self::$databaseSchema = null;
            // Testbench only registers a list of paths before the first refresh; otherwise it runs `migrate`, whose
            // options name the paths.
            $registersOnly = static::usesRefreshDatabaseTestingConcern() && RefreshDatabaseState::$migrated === false && RefreshDatabaseState::$lazilyRefreshed === false;
            parent::loadMigrationsFrom(is_array($paths) && array_is_list($paths) && !$registersOnly ? ['--path' => $paths, '--realpath' => true] : $paths);

            return;
        }

        $config = $this->app['config'];
        $schema = serialize([
            // Pest generates one child class per file; its parent identifies
            // the shared TestCase, including extra providers/migration paths.
            str_starts_with(static::class, 'P\\') ? get_parent_class($this) : static::class,
            $this->getPackageProviders($this->app),
            $config->get('database.default'),
            $config->get('database.connections.'.$config->get('database.default')),
            $config->get('vaulter.ids'),
            $config->get('vaulter.database'),
            $config->get('vaulter.tenancy.enabled'),
        ]);

        if (self::$databaseSchema !== $schema) {
            RefreshDatabaseState::$migrated = false;
            self::$databaseSchema = $schema;
        }

        // Testbench's loadMigrationsFrom() starts a separate migrator when the
        // process has already migrated, then rolls that schema back at teardown.
        // Register paths on every new app; let RefreshDatabase alone migrate.
        load_migration_paths($this->app, $paths);
    }
}
