<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Attributes\Api;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ModulesCommand;
use Vaulter\Http\Controllers\ModuleGraphInvalidController;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\OffersModules;
use Vaulter\Providers\HttpRegistrar;
use Vaulter\Webhooks\WebhooksModule;

/**
 * The core provider offers the core and the (logical) webhooks manifests and runs the module kernel:
 * it compiles every offered manifest once all providers registered, seals the result and applies the
 * declarations of the active modules (ADR-0027). It registers nothing else itself.
 */
#[Api]
final class VaulterServiceProvider extends PackageServiceProvider
{
    use OffersModules;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->offerModule(CoreModule::class);
        $this->offerModule(WebhooksModule::class);
        $this->registerModuleKernel();
    }

    public function packageBooted(): void
    {
        $this->bootModuleKernel();
    }

    /**
     * The module graph is compiled and sealed in a booting callback: Laravel runs it after every
     * provider registered and before any provider boots, so the order of package discovery does not
     * matter and no provider's boot() validates the registry. Compilation never throws — an invalid
     * graph is sealed as such: no module declaration is applied, only the kernel's own diagnostics
     * (its config and schema, the problem renderer, `vaulter:modules` and `vaulter:doctor`).
     */
    private function registerModuleKernel(): void
    {
        $this->app->singletonIf(Kernel::class);
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->singletonIf(ModuleApplier::class);
        $this->app->booting(function (): void {
            $catalog = ModuleCatalog::of($this->app);
            $catalog->seal();
            $kernel = $this->app->make(Kernel::class);
            $kernel->seal((new ModuleCompiler)->compileOffered(
                $catalog->offered(),
                $this->app->make('config')->get('vaulter.modules', []),
            ));

            $applier = $this->app->make(ModuleApplier::class);
            $applier->config('vaulter', dirname(__DIR__).'/config/vaulter.php');
            $applier->schema(dirname(__DIR__).'/database/migrations');
            $applier->registerModuleMiddleware();
            (new HttpRegistrar)->register($this->app);

            if (!$kernel->isValid()) {
                $applier->normalizeConfig();
                $this->registerDiagnosticCommands();

                return;
            }
            $applier->register($kernel->compiled());
        });
    }

    private function bootModuleKernel(): void
    {
        $kernel = $this->app->make(Kernel::class);
        (new HttpRegistrar)->boot($this->app);
        if (!$kernel->isValid()) {
            $this->registerInvalidGraphRoute();

            return;
        }

        $this->app->make(ConfigValidator::class)->validate($this->app->make(VaulterConfig::class), $this->app);
        $this->app->make(ModuleApplier::class)->boot(
            $kernel->compiled(),
            function (array $paths, string $tag): void {
                $this->publishes($paths, $tag);
            },
            function (array $paths, string $tag): void {
                $this->publishesMigrations($paths, $tag);
            },
        );
    }

    private function registerDiagnosticCommands(): void
    {
        if ($this->app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([ModulesCommand::class, DoctorCommand::class]);
            });
        }
    }

    /** One catch-all route under the Vaulter prefix answers 503 `module_graph_invalid` instead of 404. */
    private function registerInvalidGraphRoute(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        $prefix = trim($this->app->make(RoutesConfig::class)->prefix(), '/');
        // An empty prefix would make the catch-all answer 503 for the whole host application.
        if ($prefix === '') {
            return;
        }
        Route::any($prefix.'/{path?}', ModuleGraphInvalidController::class)->where('path', '.*');
    }
}
