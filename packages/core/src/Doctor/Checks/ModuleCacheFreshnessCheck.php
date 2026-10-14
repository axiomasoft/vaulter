<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Http\Middleware\EnsureModuleActive;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleApplier;

/**
 * A route cache is never rebuilt from the module manifests: a cache built under another activation keeps
 * serving the old route set (the module middleware still answers 404 for an inactive module). The routes
 * carry the module fingerprint they were loaded under; a mismatch, or an active module whose routes are
 * missing from the cache, means the caches must be rebuilt (`php artisan optimize`) and the workers restarted.
 */
final readonly class ModuleCacheFreshnessCheck implements DoctorCheck
{
    public function __construct(private Kernel $kernel, private Router $router, private Application $app) {}

    public function key(): string
    {
        return 'core.modules.cache';
    }

    public function title(): string
    {
        return 'Module fingerprint of the route cache';
    }

    public function run(DoctorContext $context): array
    {
        $fingerprint = (string) $this->kernel->fingerprint();
        $stale = [];
        $loaded = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $module = self::module($route);
            if ($module === null) {
                continue;
            }
            $loaded[$module] = true;
            if (($route->defaults[ModuleApplier::FINGERPRINT_DEFAULT] ?? null) !== $fingerprint) {
                $stale[$module] = ($stale[$module] ?? 0) + 1;
            }
        }

        $missing = [];
        foreach ($this->kernel->compiled()->routes as $declared) {
            if (!isset($loaded[$declared['module']])) {
                $missing[] = $declared['module'];
            }
        }
        $missing = array_values(array_unique($missing));
        $cached = $this->app instanceof CachesRoutes && $this->app->routesAreCached();

        $findings = [];
        if ($stale !== []) {
            ksort($stale, SORT_STRING);
            $findings[] = DoctorFinding::error(
                code: 'core.modules.route_cache_stale',
                message: 'Routes were cached under another module activation than the running one.',
                hint: 'php artisan optimize:clear && php artisan optimize, then restart the workers',
                evidence: ['modules' => implode(', ', array_keys($stale)), 'routes' => array_sum($stale), 'cached' => $cached],
            );
        }
        if ($missing !== []) {
            $findings[] = DoctorFinding::error(
                code: 'core.modules.routes_missing',
                message: 'An active module declares routes that are not registered.',
                hint: $cached ? 'php artisan optimize:clear && php artisan optimize, then restart the workers' : 'check that the module routes file mounts its routes',
                evidence: ['modules' => implode(', ', $missing), 'cached' => $cached],
            );
        }

        return $findings !== [] ? $findings : [DoctorFinding::ok(
            code: 'core.modules.cache_fresh',
            message: $cached ? 'The route cache matches the module activation.' : 'Routes are loaded from the active modules (no route cache).',
            evidence: ['cached' => $cached],
        )];
    }

    /** The module a route belongs to: the argument of its `vaulter.module:<id>` middleware. */
    private static function module(Route $route): ?string
    {
        // A route may carry closure middleware: only the named ones can be the module's.
        foreach (array_filter($route->middleware(), is_string(...)) as $middleware) {
            if (str_starts_with($middleware, EnsureModuleActive::ALIAS.':')) {
                return substr($middleware, strlen(EnsureModuleActive::ALIAS) + 1);
            }
        }

        return null;
    }
}
