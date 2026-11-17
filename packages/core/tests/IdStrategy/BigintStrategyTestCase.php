<?php

declare(strict_types=1);

namespace Vaulter\Tests\IdStrategy;

use Illuminate\Foundation\Application;
use Override;
use Vaulter\Tests\TestCase;

/**
 * Boots Vaulter with the bigint id strategy so VaulterSchema/HasVaulterKeys are
 * exercised on auto-increment keys.
 */
class BigintStrategyTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.ids.strategy', 'bigint');
    }
}
