<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests;

use Illuminate\Foundation\Application;
use Vaulter\Integration\Tests\Fixtures\TenantAwareNode;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

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

        $app['config']->set('vaulter.ids.strategy', 'ulid');
        $app['config']->set('vaulter.tenancy.enabled', true);
        $app['config']->set('vaulter.tenancy.resolver', MutableTenantResolver::class);

        // Strict tenancy uses the host resolver; model swaps remain an optional layer.
        $app['config']->set('vaulter-drive.models.node', TenantAwareNode::class);
    }
}
