<?php

declare(strict_types=1);

namespace Vaulter\Tests\Upgrade;

use function Orchestra\Testbench\load_migration_paths;

use Vaulter\Tests\TestCase;

/** Historical fixtures own their schema and migration lifecycle. */
abstract class FixtureTestCase extends TestCase
{
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/../../database/migrations');
    }
}
