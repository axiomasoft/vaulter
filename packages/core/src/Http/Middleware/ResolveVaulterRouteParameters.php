<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Http\Support\HttpResourceResolver;

final readonly class ResolveVaulterRouteParameters
{
    public function __construct(private HttpResourceResolver $resolver) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route instanceof Route) {
            foreach (['storage', 'node'] as $parameter) {
                $value = $route->parameters()[$parameter] ?? null;
                if (is_int($value) || is_string($value)) {
                    $route->setParameter($parameter, $this->resolver->{$parameter}($value));
                }
            }
        }

        return $next($request);
    }
}
