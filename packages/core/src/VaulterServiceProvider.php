<?php

declare(strict_types=1);

namespace Vaulter;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Providers\AccessRegistrar;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\Providers\ConsoleRegistrar;
use Vaulter\Providers\DoctorRegistrar;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\Providers\HttpRegistrar;
use Vaulter\Providers\SchedulingRegistrar;

final class VaulterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasConfigFile()
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api');
    }

    public function packageRegistered(): void
    {
        (new BindingsRegistrar)->register($this->app);
        $normalizer = $this->app->make(ConfigNormalizer::class);
        $normalizer->registerDefaults('vaulter', __DIR__.'/../config/vaulter.php');
        $this->app->booting(function (): void {
            $normalizer = $this->app->make(ConfigNormalizer::class);
            $normalizer->normalize($this->app->make('config'), $normalizer->packageDefaults());
        });
        $this->registerModuleKernel();
        (new AccessRegistrar)->register($this->app);
        (new EventsRegistrar)->register($this->app);
        (new ConsoleRegistrar)->register($this->app);
        (new DoctorRegistrar)->register($this->app);
        (new HttpRegistrar)->register($this->app);
    }

    public function packageBooted(): void
    {
        $this->app->make(ConfigValidator::class)->validate($this->app->make(VaulterConfig::class), $this->app);
        (new HttpRegistrar)->boot($this->app);
        (new AccessRegistrar)->boot($this->app);
        (new BindingsRegistrar)->boot($this->app);
        (new SchedulingRegistrar)->register($this->app);
    }

    /**
     * The module graph is compiled and sealed in a booting callback: Laravel runs it after every
     * provider registered and before any provider boots, so the order of package discovery does not
     * matter and no provider's boot() validates the registry. Compilation never throws — an invalid
     * graph is sealed as such and reported by the kernel.
     */
    private function registerModuleKernel(): void
    {
        ModuleCatalog::of($this->app);
        $this->app->singletonIf(Kernel::class);
        $this->app->booting(function (): void {
            $catalog = ModuleCatalog::of($this->app);
            $catalog->seal();
            $this->app->make(Kernel::class)->seal((new ModuleCompiler)->compileOffered(
                $catalog->offered(),
                $this->app->make('config')->get('vaulter.modules', []),
            ));
        });
    }
}
