<?php

declare(strict_types=1);

namespace Vaulter\Tests\IdStrategy;

use Illuminate\Foundation\Application;
use Vaulter\Tests\TestCase;

/**
 * Boots Vaulter with the uuid7 id strategy (native uuid columns).
 */
class Uuid7StrategyTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.ids.default', 'uuid7');
    }
}
