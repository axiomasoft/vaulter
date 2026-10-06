<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Policies\NodePolicy;
use Vaulter\Policies\WebhookEndpointPolicy;
use Vaulter\Support\TenantFootgunGuard;

final class AccessRegistrar
{
    public function register(Application $app): void
    {
        $app->bind(OperationGuard::class);

        // Drive ACL (M2a / H.2): the host-role driver manager resolves the
        // configured PermissionDriver (default 'database'), keyed per driver-key, and
        // enforces the null-driver fail-loud guard in production (D6″). The
        // AccessResolver (Vaulter-owned, NON-swappable) is auto-wired from it.
        $app->singleton(
            PermissionDriverManager::class,
            fn (): PermissionDriverManager => new PermissionDriverManager(
                accessConfig: $app->make(AccessConfig::class),
                container: $app,
                isProduction: $app->environment('production'),
            ),
        );

        // The contract binding resolves the active driver — so a host that injects
        // PermissionDriver directly (or swaps it in tests) gets the configured lane.
        $app->bind(
            PermissionDriver::class,
            fn (): PermissionDriver => $app->make(PermissionDriverManager::class)->driver(),
        );

    }

    public function boot(Application $app): void
    {
        $this->registerPolicies();
        (new TenantFootgunGuard($app->make(TenancyConfig::class), $app->make(VaulterConfig::class)))->check(isProduction: $app->environment('production'));
    }

    private function registerPolicies(): void
    {
        Gate::policy(app(VaulterConfig::class)->model('node'), NodePolicy::class);

        // Cover the base model too, so a host that route-binds the base Node (not a
        // subclass) still hits the policy.
        if (app(VaulterConfig::class)->model('node') !== Node::class) {
            Gate::policy(Node::class, NodePolicy::class);
        }

        // The webhook-endpoint management surface (§2.11 / D14 / M3b) is a global
        // admin resource, authorized by the WebhookEndpointPolicy (manage ability).
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);

        if (app(VaulterConfig::class)->model('webhook_endpoint') !== WebhookEndpoint::class) {
            Gate::policy(app(VaulterConfig::class)->model('webhook_endpoint'), WebhookEndpointPolicy::class);
        }
    }
}
