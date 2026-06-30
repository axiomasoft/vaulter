<?php

declare(strict_types=1);

namespace Vaulter\Tests\ActivityGate;

use Illuminate\Foundation\Application;
use Vaulter\Tests\TestCase;

/**
 * Boots the package with the 'activity' feature turned OFF before the provider
 * boots, so the RecordsActivity subscriber is never registered. Exercises the gate:
 * with auditing disabled the Drive events fire unobserved and no rows are written.
 */
abstract class ActivityDisabledTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.features.activity', false);
    }
}
