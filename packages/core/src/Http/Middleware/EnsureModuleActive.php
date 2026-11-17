<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleGraphException;

/**
 * `vaulter.module:<id>` — every module route group carries it, so a route that outlived its module in a
 * route cache answers as if it did not exist (404), and an invalid module graph answers 503
 * `module_graph_invalid`. Reads the sealed kernel only; no I/O.
 *
 * A route cached under another module fingerprint is still served: `vaulter:doctor` reports the stale
 * cache (`core.modules.cache`).
 */
#[Internal]
final readonly class EnsureModuleActive
{
    public const string ALIAS = 'vaulter.module';

    public function __construct(private Kernel $kernel) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (!$this->kernel->isValid()) {
            throw ModuleGraphException::invalid($this->kernel->reasons());
        }
        if (!$this->kernel->isActive($module)) {
            throw new NotFoundHttpException;
        }

        // The fingerprint is a route default, not a parameter of the action.
        $route = $request->route();
        if ($route instanceof Route) {
            $route->forgetParameter(ModuleApplier::FINGERPRINT_DEFAULT);
        }

        return $next($request);
    }
}
