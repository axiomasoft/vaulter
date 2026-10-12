<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Azgard\Contracts\AzgardBackedUser;
use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * Wires the AzGuard/Corex permission bridge (master plan P1 #2). It binds the Vaulter
 * {@see AzgardGuard} seam to an {@see AzgardGuardAdapter} built from the CURRENT
 * request's AzGuard-backed user, so that once the host selects the `azgard` permission
 * driver (`config('vaulter.drivers.permission') = 'azgard'`) the driver resolves real
 * AzGuard checks with no further wiring.
 *
 * When the request has no AzGuard-backed user (a guest, or a User that does not
 * implement {@see AzgardBackedUser}) the bound guard is the deny-all
 * {@see NullAzgardGuard} — a safe pass-through that raises no access, never a fatal.
 *
 * The host still owns the ecosystem alignment this bridge cannot do for it: ulid morphs
 * (`vaulter.ids.default=ulid` + `corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`),
 * registering workspace/owner types as Corex morph aliases, and adding
 * `implements AzgardBackedUser` (satisfied for free by AzGuard's HasAzGuard +
 * HasScopedRoles traits) to its User model.
 */
final class VaulterAzgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-azgard.php', 'vaulter-azgard');

        if (!$this->app->make('config')->has('vaulter.permission_drivers.azgard')) {
            $this->app->make('config')->set('vaulter.permission_drivers.azgard', AzgardPermissionDriver::class);
        }

        $this->app->bind(AzgardGuard::class, static function (Container $app): AzgardGuard {
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

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vaulter-azgard.php' => $this->app->configPath('vaulter-azgard.php'),
            ], 'vaulter-azgard-config');
        }
    }
}
