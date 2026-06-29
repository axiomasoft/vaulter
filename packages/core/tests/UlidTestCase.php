<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;

/**
 * Boots the package in ULID + tenant mode so the migrations and key handling are
 * exercised in their multi-tenant configuration.
 */
class UlidTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.id_type', 'ulid');
        $app['config']->set('vaulter.tenant.enabled', true);
    }
}
