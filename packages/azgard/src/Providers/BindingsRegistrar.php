<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardModule;
use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Modules\ModuleBindings;

/**
 * Wires the AzGuard v1 permission bridge: the config accessor. The `azgard` permission driver is the module's
 * contribution to the PermissionDriver slot, and the guard seam is not bound here: a host that wants one guard
 * for every actor names its class in `vaulter-azgard.guard` (the optional capability of {@see AzgardModule});
 * otherwise the driver builds an {@see AzgardGuardAdapter} over each AzGuard-backed actor and
 * passes through for the rest.
 *
 * The host still owns the AzGuard panel this bridge cannot declare for it: the User is a subject model of the
 * panel, the workspace (drive owner) morph aliases are assignment scope types of the panel, and the User adds
 * `implements AzgardBackedUser` (satisfied for free by AzGuard's HasAzGuard trait).
 */
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(AzgardConfig::class, static fn (Container $app): AzgardConfig => new AzgardConfig($app->make('config')));
    }

    public function boot(Application $app): void
    {
        // Fail at boot on a guard class that does not implement the seam.
        $app->make(AzgardConfig::class)->guard();
    }
}
