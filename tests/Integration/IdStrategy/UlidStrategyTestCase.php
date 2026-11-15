<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\IdStrategy;

use Illuminate\Foundation\Application;
use Vaulter\Integration\Tests\TestCase;

/**
 * Boots Vaulter with the ulid id strategy (the default).
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
