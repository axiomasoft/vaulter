<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Packman\Attributes\Internal;

/**
 * For a test file that proves it needs no database: the suite TestCase still boots the application and its providers, but the
 * migrations are never loaded, so no schema is built before the test. Bind it per path from the suite's Pest.php
 * (`uses(WithoutDatabaseSchema::class)->in(...)`); a test that then reaches the database fails loudly, which is the proof.
 *
 * A trait method wins over the inherited TestCase one, so the binding does not collide with the TestCase bound to the same path.
 */
#[Internal]
trait WithoutDatabaseSchema
{
    protected function defineDatabaseMigrations(): void {}
}
