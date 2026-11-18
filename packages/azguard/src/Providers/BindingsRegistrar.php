<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Vaulter\AzGuard\AzGuardGuardAdapter;
use Vaulter\AzGuard\AzGuardModule;
use Vaulter\AzGuard\Configuration\AzGuardOptions;
use Vaulter\Configuration\CompiledOptionsCache;
use Vaulter\Modules\ModuleBindings;

/**
 * Wires the AzGuard v1 permission bridge: the config accessor. The `azguard` permission driver is the module's
 * contribution to the PermissionDriver slot, and the guard seam is not bound here: a host that wants one guard
 * for every actor names its class in `vaulter-azguard.guard` (the optional capability of {@see AzGuardModule});
 * otherwise the driver builds an {@see AzGuardGuardAdapter} over each AzGuard-backed actor and
 * passes through for the rest.
 *
 * The host still owns the AzGuard panel this bridge cannot declare for it: the User is a subject model of the
 * panel, the workspace (drive owner) morph aliases are assignment scope types of the panel, and the User adds
 * `implements AzGuardBackedUser` (satisfied for free by AzGuard's HasAzGuard trait).
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(AzGuardOptions::class, static fn (Container $app): AzGuardOptions => CompiledOptionsCache::resolve($app, 'vaulter-azguard', static fn (array $raw): AzGuardOptions => AzGuardOptions::fromArray($raw)));
    }

    public function boot(Application $app): void
    {
        // Fail at boot on a guard class that does not implement the seam.
        $app->make(AzGuardOptions::class)->guard();
    }
}
