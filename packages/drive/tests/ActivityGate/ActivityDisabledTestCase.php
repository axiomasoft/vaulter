<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\ActivityGate;

use Illuminate\Foundation\Application;
use Vaulter\Drive\Tests\TestCase;

/**
 * Boots the package with the 'activity' feature turned OFF. Exercises the gate: with
 * auditing disabled the EventRecorder skips the activity sink and no rows are written.
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
