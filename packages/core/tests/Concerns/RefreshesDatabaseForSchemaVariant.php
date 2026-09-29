<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Isolates a TestCase whose migrations depend on non-default Vaulter config.
 *
 * RefreshDatabase keeps one process-wide "migrated" flag. PostgreSQL uses one
 * persistent database for the suite, so a schema variant must migrate its own
 * shape and then force the following default TestCase to restore the base shape.
 */
trait RefreshesDatabaseForSchemaVariant
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
