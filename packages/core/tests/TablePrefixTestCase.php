<?php

declare(strict_types=1);

namespace DocVault\Tests;

use Illuminate\Foundation\Application;

/**
 * Boots the package with a non-default table prefix so the migrations and models
 * are exercised against a custom naming convention.
 */
class TablePrefixTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('docvault.table_prefix', 'custom_');
    }
}
