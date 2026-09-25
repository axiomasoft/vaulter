<?php

declare(strict_types=1);

namespace Vaulter\Tests\IdStrategy;

use Illuminate\Foundation\Application;
use Vaulter\Tests\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Tests\TestCase;

/**
 * Boots Vaulter with the bigint id strategy so VaulterSchema/HasVaulterKeys are
 * exercised on auto-increment keys.
 */
class BigintStrategyTestCase extends TestCase
{
    use RefreshesDatabaseForSchemaVariant;

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.ids.default', 'bigint');
    }
}
