<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Providers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Azgard\AzgardGuardAdapter;
use Vaulter\Azgard\AzgardPermissionDriver;
use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Azgard\Contracts\AzgardBackedUser;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\NullAzgardGuard;
use Vaulter\Modules\ModuleBindings;

/**
 * Wires the AzGuard v1 permission bridge. It binds the Vaulter {@see AzgardGuard} seam to an
 * {@see AzgardGuardAdapter} built from the CURRENT request's AzGuard-backed user, so that once the host
 * selects the `azgard` permission driver (`vaulter.access.permission_driver = 'azgard'`) the driver resolves
 * real AzGuard checks with no further wiring.
 *
 * When the request has no AzGuard-backed user (a guest, or a User that does not implement
 * {@see AzgardBackedUser}) the bound guard is the deny-all {@see NullAzgardGuard} — a safe pass-through that
 * raises no access, never a fatal.
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

        // A host driver map that names the key keeps it, null included.
        $config = $app->make('config');
        if (!$config->has('vaulter.access.permission_drivers.azgard')) {
            $config->set('vaulter.access.permission_drivers.azgard', AzgardPermissionDriver::class);
        }

        $app->bind(AzgardGuard::class, static function (Container $app): AzgardGuard {
            $user = $app->make(AuthFactory::class)->guard()->user();

            if (!$user instanceof AzgardBackedUser) {
                return new NullAzgardGuard;
            }

            return new AzgardGuardAdapter(
                user: $user,
                panelId: $app->make(AzgardConfig::class)->panelId(),
            );
        });
    }

    public function boot(Application $app): void {}
}
