<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\AzGuard\Providers\AzGuardPackageRegistrar;
use Vaulter\Modules\OffersModules;

/**
 * Vaulter AzGuard — AzGuard host-role permission driver integration. Registers Packman facets and offers {@see AzGuardModule}.
 */
#[Api(since: '1.0')]
final class VaulterAzGuardServiceProvider extends ServiceProvider
{
    use OffersModules;
    use RegistersPackageRegistrars {
        RegistersPackageRegistrars::register as protected registerRegistrars;
        RegistersPackageRegistrars::boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        AzGuardPackageRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        if ($this->moduleProviderRegistered()) {
            return;
        }

        $this->registerRegistrars();
    }

    public function boot(): void
    {
        if ($this->moduleProviderBooted()) {
            return;
        }

        $this->bootRegistrars();
        $this->markModuleProviderBooted();
    }
}
