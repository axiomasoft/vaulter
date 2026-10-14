<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Closure;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Http\Middleware\EnsureModuleActive;

/**
 * Applies the sealed declarations of the active modules to the application (architecture#module-system,
 * lifecycle). `register()` runs in the core provider's booting callback, after compilation and before any
 * provider boots; `boot()` runs while the core provider boots. Nothing here reads the database or the network.
 *
 * @internal
 */
final class ModuleApplier
{
    /** The route default that carries the module fingerprint into a route cache. */
    public const string FINGERPRINT_DEFAULT = '_vaulter_module_fp';

    /** @var list<ModuleBindings> */
    private array $bindings = [];

    public function __construct(private readonly Application $app) {}

    /**
     * Config defaults, container wiring, capabilities, middleware aliases, listeners, commands and schedules
     * of the active modules; the migrations of every offered module.
     */
    public function register(CompiledModules $modules): void
    {
        foreach ($modules->configs as $root => $file) {
            $this->config($root, $file);
        }
        $this->normalizeConfig();

        foreach ($modules->schemaPaths as $path) {
            $this->schema($path);
        }

        $router = $this->app->make(Router::class);
        foreach ($modules->routeMiddleware as $alias => $middleware) {
            $router->aliasMiddleware($alias, $middleware);
        }

        foreach ($modules->bindings as $class) {
            $bindings = new $class;
            $bindings->register($this->app);
            $this->bindings[] = $bindings;
        }

        foreach ($modules->providers as $contract => $providers) {
            $single = ($modules->capabilities[$contract] ?? null)?->cardinality !== Cardinality::Many;
            foreach ($providers as $provider) {
                $this->provide($provider, $single);
            }
        }

        $events = $this->app->make('events');
        foreach ($modules->listeners as $listener) {
            $events->listen($listener['event'], $listener['listener']);
        }

        $this->commands($modules->commands);

        if ($modules->schedules !== []) {
            $this->schedules($modules->schedules);
        }
    }

    /**
     * Routes (each group behind `vaulter.module:<id>`, carrying the fingerprint), translations and the
     * publishable files of the active modules, then the boot of their container wiring.
     *
     * @param Closure(array<string, string>, string): void $publishes  registers publishable paths under a tag
     * @param Closure(array<string, string>, string): void $migrations registers publishable migrations under a tag
     */
    public function boot(CompiledModules $modules, Closure $publishes, Closure $migrations): void
    {
        foreach ($modules->routes as $route) {
            $this->routes($route['module'], $route['file'], $modules->fingerprint);
        }

        foreach ($modules->translations as $namespace => $dir) {
            $this->translations($dir, $namespace);
        }

        if ($this->app->runningInConsole()) {
            foreach ($modules->configs as $root => $file) {
                $publishes([$file => $this->app->configPath($root.'.php')], $root.'-config');
            }
            foreach ($modules->modules as $module) {
                foreach ($module->schemaPaths as $path) {
                    $migrations([$path => $this->app->databasePath('migrations')], self::migrationsTag($module->id->value));
                }
            }
        }

        foreach ($this->bindings as $bindings) {
            $bindings->boot($this->app);
        }
    }

    /** The publish tag of a module's migrations: `vaulter-migrations` for the core, `vaulter-<id>-migrations` otherwise. */
    public static function migrationsTag(string $module): string
    {
        return $module === 'core' ? 'vaulter-migrations' : 'vaulter-'.$module.'-migrations';
    }

    /**
     * The module middleware alias: registered whatever the graph, because a cached route still names it.
     */
    public function registerModuleMiddleware(): void
    {
        $this->app->make(Router::class)->aliasMiddleware(EnsureModuleActive::ALIAS, EnsureModuleActive::class);
    }

    /**
     * Merges the shipped `$file` under the host's `$root` (the host wins, like `mergeConfigFrom`) and registers
     * it as the defaults the normalizer fills nested keys from.
     */
    public function config(string $root, string $file): void
    {
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make(Repository::class);
            $shipped = require $file;
            if (!is_array($shipped)) {
                throw new InvalidConfigurationException('[Vaulter] Package config file "'.$file.'" must return an array.');
            }
            $host = $config->get($root, []);
            $config->set($root, array_merge($shipped, is_array($host) ? $host : []));
        }
        $this->app->make(ConfigNormalizer::class)->registerDefaults($root, $file);
    }

    public function normalizeConfig(): void
    {
        $normalizer = $this->app->make(ConfigNormalizer::class);
        $normalizer->normalize($this->app->make(Repository::class), $normalizer->packageDefaults());
    }

    /** Registers `$path` with the migrator: a module's schema is installed whether the module is active or not. */
    public function schema(string $path): void
    {
        $this->afterResolving('migrator', static function (object $migrator) use ($path): void {
            if (method_exists($migrator, 'path')) {
                $migrator->path($path);
            }
        });
    }

    private function translations(string $dir, string $namespace): void
    {
        $this->afterResolving('translator', static function (object $translator) use ($dir, $namespace): void {
            if (method_exists($translator, 'addNamespace')) {
                $translator->addNamespace($namespace, $dir);
            }
        });
    }

    private function provide(CapabilityProvider $provider, bool $single): void
    {
        $implementation = $provider->implementation;
        if ($implementation !== null) {
            $this->app->singletonIf($implementation);
            if ($single) {
                $this->app->singleton($provider->contract, $implementation);
            }

            return;
        }

        $key = (string) $provider->configKey;
        $default = $provider->default;
        $this->app->singleton($provider->contract, static function (Application $app) use ($key, $default, $provider): object {
            $class = $app->make(Repository::class)->get($key);
            $class = is_string($class) && $class !== '' ? $class : $default;
            if ($class === null || !is_a($class, $provider->contract, true)) {
                throw MissingExtensionException::capability($provider->contract);
            }

            $instance = $app->make($class);
            if (!$instance instanceof $provider->contract) {
                throw MissingExtensionException::capability($provider->contract);
            }

            return $instance;
        });
    }

    /** @param list<class-string> $commands */
    private function commands(array $commands): void
    {
        if ($commands === [] || !$this->app->runningInConsole()) {
            return;
        }
        Artisan::starting(static function (Artisan $artisan) use ($commands): void {
            $artisan->resolveCommands($commands);
        });
    }

    /** @param list<class-string<ModuleSchedule>> $schedules */
    private function schedules(array $schedules): void
    {
        $app = $this->app;
        $define = static function (Schedule $scheduler) use ($app, $schedules): void {
            $config = $app->make(Repository::class);
            if (!(new ScheduleConfig($config))->enabled()) {
                return;
            }
            foreach ($schedules as $class) {
                (new $class)->define($scheduler, $config);
            }
        };
        $app->afterResolving(Schedule::class, $define);
        if ($app->resolved(Schedule::class)) {
            $define($app->make(Schedule::class));
        }
    }

    /**
     * Loads `$file` behind `vaulter.module:<id>` and stamps every route it adds with the fingerprint, which
     * survives `route:cache` (a cached collection is never reloaded from the file).
     */
    private function routes(string $module, string $file, string $fingerprint): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }
        $router = $this->app->make(Router::class);
        $before = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $before[spl_object_id($route)] = true;
        }
        $router->middleware([EnsureModuleActive::ALIAS.':'.$module])->group($file);
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (!isset($before[spl_object_id($route)])) {
                $route->defaults(self::FINGERPRINT_DEFAULT, $fingerprint);
            }
        }
    }

    /** @param Closure(object): void $callback */
    private function afterResolving(string $abstract, Closure $callback): void
    {
        $this->app->afterResolving($abstract, $callback);
        if ($this->app->resolved($abstract)) {
            $resolved = $this->app->make($abstract);
            if (is_object($resolved)) {
                $callback($resolved);
            }
        }
    }
}
