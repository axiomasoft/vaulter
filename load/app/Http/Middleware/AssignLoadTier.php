<?php

declare(strict_types=1);

namespace Vaulter\Load\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Support\LoadTier;

/**
 * Binds compose `TIER` into request context for package observability seams (P8.3).
 */
final class AssignLoadTier
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        LoadTier::set(env('TIER'));

        return $next($request);
    }
}
