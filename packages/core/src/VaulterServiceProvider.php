<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Modules\OffersModules;
use Vaulter\Providers\CoreKernelRegistrar;

/**
 * The core provider offers {@see CoreModule} and delegates runtime wiring to {@see CoreKernelRegistrar}.
 */
#[Api(since: '1.0')]
final class VaulterServiceProvider extends ServiceProvider
{
    use OffersModules;
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        CoreKernelRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->offerModule(CoreModule::class);
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter');
        $this->bootRegistrars();
        $this->app->make(CoreKernelRegistrar::class)->bootWithPublisher(
            $this->app,
            function (array $paths, string $tag): void {
                $this->publishes($paths, $tag);
            },
        );
    }
}
