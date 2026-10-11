<?php

declare(strict_types=1);

namespace Vaulter\Tests\IdStrategy;

use Illuminate\Foundation\Application;
use Vaulter\Tests\TestCase;

/**
 * Boots Vaulter with the ulid id strategy (the D9 default).
 */
class UlidStrategyTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.ids.strategy', 'ulid');
    }
}
