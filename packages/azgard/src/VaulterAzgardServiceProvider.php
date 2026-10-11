<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Vaulter\Azgard\Configuration\AzgardConfig;
use Vaulter\Azgard\Contracts\AzgardBackedUser;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Doctor\AzgardDependencyCheck;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Doctor\DoctorRegistry;

/**
 * Wires the AzGuard v1 permission bridge (master plan P1 #2). It binds the Vaulter
 * {@see AzgardGuard} seam to an {@see AzgardGuardAdapter} built from the CURRENT
 * request's AzGuard-backed user, so that once the host selects the `azgard` permission
 * driver (`vaulter.access.permission_driver = 'azgard'`) the driver resolves real
 * AzGuard checks with no further wiring.
 *
 * When the request has no AzGuard-backed user (a guest, or a User that does not
 * implement {@see AzgardBackedUser}) the bound guard is the deny-all
 * {@see NullAzgardGuard} — a safe pass-through that raises no access, never a fatal.
 *
 * The host still owns the AzGuard panel this bridge cannot declare for it: the User is a
 * subject model of the panel, the workspace (drive owner) morph aliases are assignment
 * scope types of the panel, and the User adds `implements AzgardBackedUser` (satisfied
 * for free by AzGuard's HasAzGuard trait).
 */
final class VaulterAzgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-azgard.php', 'vaulter-azgard');

        $this->app->singleton(AzgardConfig::class, fn (Container $app): AzgardConfig => new AzgardConfig($app->make('config')));
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-azgard', path: __DIR__.'/../config/vaulter-azgard.php');

        if (!$this->app->make('config')->has('vaulter.access.permission_drivers.azgard')) {
            $this->app->make('config')->set('vaulter.access.permission_drivers.azgard', AzgardPermissionDriver::class);
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

        $this->app->tag([AzgardDependencyCheck::class], DoctorRegistry::TAG);
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
