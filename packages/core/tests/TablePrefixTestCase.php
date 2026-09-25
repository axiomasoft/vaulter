<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Vaulter\Tests\Concerns\RefreshesDatabaseForSchemaVariant;

/**
 * Boots the package with a non-default table prefix so the migrations and models
 * are exercised against a custom naming convention.
 */
class TablePrefixTestCase extends TestCase
{
    use RefreshesDatabaseForSchemaVariant;

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.table_prefix', 'custom_');
    }
}
