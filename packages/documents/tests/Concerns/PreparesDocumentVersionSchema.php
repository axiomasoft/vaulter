<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Migrates the feature-dependent document schema for each variant test.
 *
 * RefreshDatabase caches migration completion process-wide. On PostgreSQL an
 * earlier base test can therefore migrate with document_versions disabled and
 * leave the later Document test cases without their required table.
 */
trait PreparesDocumentVersionSchema
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
