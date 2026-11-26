<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Configuration\AccessOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Policies\NodePolicy;
use Vaulter\Modules\ModuleBindings;

/** Drive access, declared by DriveModule: permission drivers, the operation guard and the node policy. */
#[Internal]
final class AccessRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(OperationGuard::class);

        $app->scoped(
            PermissionDriverManager::class,
            static fn (Application $app): PermissionDriverManager => new PermissionDriverManager(
                accessOptions: $app->make(AccessOptions::class),
                container: $app,
                isProduction: $app->environment('production'),
            ),
        );

        $app->bind(
            PermissionDriver::class,
            static fn (Application $app): PermissionDriver => $app->make(PermissionDriverManager::class)->driver(),
        );
    }

    public function boot(Application $app): void
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-drive', []) : [];
        $nodeModel = DriveOptions::fromArray(is_array($raw) ? $raw : [], 'vaulter-drive')->models->node;
        Gate::policy($nodeModel, NodePolicy::class);

        // Cover the base model too, so a host that route-binds the base Node (not a
        // subclass) still hits the policy.
        if ($nodeModel !== Node::class) {
            Gate::policy(Node::class, NodePolicy::class);
        }
    }
}
