<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class RoutesAuthCheck implements DoctorCheck
{
    public function __construct(private RoutesConfig $routes, private Repository $config) {}

    public function key(): string
    {
        return 'core.routes.auth';
    }

    public function title(): string
    {
        return 'Authentication on the authenticated routes';
    }

    public function run(DoctorContext $context): array
    {
        $guards = array_keys((array) $this->config->get('auth.guards', []));
        $authenticating = [];
        foreach ($this->routes->authenticatedMiddleware() as $middleware) {
            if ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || $middleware === Authenticate::class || str_starts_with($middleware, Authenticate::class.':')) {
                $authenticating[] = $middleware;
            }
        }

        if ($authenticating === []) {
            return [DoctorFinding::error(
                code: 'core.routes.no_auth_middleware',
                message: 'vaulter.routes.middleware.authenticated has no auth middleware: the API would answer anonymous requests.',
                hint: "add 'auth:<guard>', e.g. 'auth:sanctum'",
            )];
        }

        $unknown = [];
        foreach ($authenticating as $middleware) {
            $names = str_contains($middleware, ':') ? explode(',', substr($middleware, (int) strpos($middleware, ':') + 1)) : [];
            foreach ($names as $guard) {
                if (!in_array($guard, $guards, true)) {
                    $unknown[] = $guard;
                }
            }
        }
        if ($unknown !== []) {
            return [DoctorFinding::error(
                code: 'core.routes.unknown_guard',
                message: 'The auth middleware names a guard that auth.guards does not define.',
                hint: 'define the guard in config/auth.php or name an existing one',
                evidence: ['guards' => implode(', ', array_values(array_unique($unknown)))],
            )];
        }

        return [DoctorFinding::ok(code: 'core.routes.auth_ok', message: 'The authenticated routes require authentication.', evidence: ['middleware' => implode(', ', $authenticating)])];
    }
}
