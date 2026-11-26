<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Closure;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Console\DoctorCommand;
use Vaulter\Console\ModulesCommand;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\CoreModule;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Http\Controllers\ModuleGraphInvalidController;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\VaulterExtensionFacets;
use Vaulter\Modules\VaulterPluginActivationGate;
use Vaulter\Security\ProductionInvariants;

/**
 * Registers Packman plugin/extension facets for {@see CoreModule}, compiles the Vaulter
 * module graph during booting, validates core configuration on boot, and guards production runs.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class CoreKernelRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(self::class);
        $app->scopedIf(Kernel::class, static function (Application $scope): Kernel {
            $kernel = new Kernel($scope);
            if ($scope->bound(CompiledModules::class)) {
                $kernel->seal($scope->make(CompiledModules::class));
            } elseif ($scope->bound(InvalidModuleGraph::class)) {
                $kernel->seal($scope->make(InvalidModuleGraph::class));
            }

            return $kernel;
        });
        $app->singletonIf(ConfigNormalizer::class);
        $app->singletonIf(ModuleApplier::class);

        $this->registerPackmanFacets($app);

        $app->booting(function () use ($app): void {
            $catalog = ModuleCatalog::of($app);
            $catalog->seal();

            $kernel = $app->make(Kernel::class);
            $config = $app->make(Repository::class);
            $overrides = $config->get('vaulter.modules', []);
            $disabled = $config->get('packman.disabled_plugins', []);
            $gate = ExtensionRegistrar::activationGateFor($app, except: VaulterPluginActivationGate::class);
            $compiled = (new ModuleCompiler)->compileOffered($catalog->offered(), $overrides, $catalog->pluginSpecs(),
                static fn (string $id): bool => (!is_array($disabled) || !in_array($id, $disabled, true)) && ($gate?->isEnabled($id) ?? true),
            );
            $kernel->seal($compiled);
            $app->instance($compiled::class, $compiled);

            $applier = $app->make(ModuleApplier::class);
            $applier->config('vaulter', dirname(__DIR__, 2).'/config/vaulter.php');
            $applier->schema(dirname(__DIR__, 2).'/database/migrations');
            $applier->registerModuleMiddleware();
            (new HttpRegistrar)->register($app);

            if (!$kernel->isValid()) {
                $applier->normalizeConfig();
                $this->registerDiagnosticCommands($app);

                return;
            }

            $catalog->activateFacets($kernel->compiled());
            $applier->register($kernel->compiled());
        });

        $app->booted(function () use ($app): void {
            if ($app->runningInConsole() || !$app->environment('production') || !$app->make(Kernel::class)->isValid()) {
                return;
            }

            $app->make(ProductionInvariants::class)->assertSecure();
        });

        // One listener for the canonical Symfony command name: global options, aliases and Artisan::call() resolve first.
        $app->make('events')->listen(CommandStarting::class, function (CommandStarting $event) use ($app): void {
            if (!$app->environment('production') || !ProductionInvariants::guards($event->command) || !$app->make(Kernel::class)->isValid()) {
                return;
            }

            $app->make(ProductionInvariants::class)->assertSecure();
        });
    }

    #[Override]
    public function boot(Application $app): void {}

    /** @param Closure(array<string, string>, string): void $publishes */
    public function bootWithPublisher(Application $app, Closure $publishes): void
    {
        $kernel = $app->make(Kernel::class);
        (new HttpRegistrar)->boot($app);

        if (!$kernel->isValid()) {
            $this->registerInvalidGraphRoute($app);

            return;
        }

        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter', []) : [];
        $options = VaulterOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'vaulter');
        if ($options->tenancy->enabled) {
            $options->tenancy->requireResolver();
            if (!self::isTenantResolver($app->make(TenantResolver::class))) {
                throw new TenancyMisconfiguredException('TenantResolver binding returned an invalid object.');
            }
        }

        $app->make(ModuleApplier::class)->boot($kernel->compiled(), $publishes);
    }

    private function registerPackmanFacets(Application $app): void
    {
        $app->register(PackmanServiceProvider::class);
        $app->register(PluginsServiceProvider::class);
        $app->register(SchemaServiceProvider::class);
        ExtensionRegistrar::addActivationGate($app, VaulterPluginActivationGate::class);

        VaulterExtensionFacets::register($app, CoreModule::class, dirname(__DIR__, 2).'/composer.json');

    }

    private function registerDiagnosticCommands(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([ModulesCommand::class, DoctorCommand::class]);
            });
        }
    }

    /** One catch-all route under the Vaulter prefix answers 503 `module_graph_invalid` instead of 404. */
    private function registerInvalidGraphRoute(Application $app): void
    {
        if ($app instanceof CachesRoutes && self::isRoutesCached($app)) {
            return;
        }

        $rawPrefix = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter.routes.prefix', 'api/vaulter') : 'api/vaulter';
        $prefix = trim(is_string($rawPrefix) ? $rawPrefix : 'api/vaulter', '/');

        if ($prefix === '') {
            return;
        }

        Route::any($prefix.'/{path?}', ModuleGraphInvalidController::class)->where('path', '.*');
    }

    private static function isTenantResolver(mixed $resolved): bool
    {
        return $resolved instanceof TenantResolver;
    }

    private static function isRoutesCached(CachesRoutes $app): bool
    {
        return $app->routesAreCached();
    }
}
