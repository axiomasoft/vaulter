<?php

declare(strict_types=1);

namespace Vaulter\Tests;

use Illuminate\Foundation\Application;
use Vaulter\Tests\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Tests\Fixtures\TenantAwareNode;

/**
 * Boots the package in ULID + tenant mode so the migrations and key handling are
 * exercised in their multi-tenant configuration.
 */
class UlidTestCase extends TestCase
{
    use RefreshesDatabaseForSchemaVariant;

    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter.ids.strategy', 'ulid');
        $app['config']->set('vaulter.tenancy.enabled', true);

        // This suite exercises ULID key handling + tenant_id COLUMN emission, not
        // query-level tenant isolation — swap in the non-enforcing test double so
        // boot doesn't trip the F-P1.9-01 footgun guard (which exists to catch
        // exactly "tenant.enabled=true + still the default node model").
        $app['config']->set('vaulter.models.node', TenantAwareNode::class);
    }
}
