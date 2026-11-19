<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Filament\Providers\FilamentPackageRegistrar;
use Vaulter\Modules\OffersModules;

/**
 * Vaulter Filament — Filament panel resources and surface integration. Registers Packman facets and offers {@see FilamentModule}.
 */
#[Api(since: '1.0')]
final class VaulterFilamentServiceProvider extends ServiceProvider
{
    use OffersModules;
    use RegistersPackageRegistrars {
        RegistersPackageRegistrars::register as protected registerRegistrars;
        RegistersPackageRegistrars::boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        FilamentPackageRegistrar::class,
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
