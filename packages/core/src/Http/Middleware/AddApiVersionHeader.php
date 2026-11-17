<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks every response of the versioned API with its major version, so a client can
 * refuse a server it was not built for. The unversioned routes still sharing the stack
 * are left as they are.
 */
#[Internal]
final class AddApiVersionHeader
{
    public const string HEADER = 'X-Vaulter-Api-Version';

    public const string VERSION = '1';

    public const string ROUTE_PREFIX = 'vaulter.v1.';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (self::versioned($request)) {
            $response->headers->set(self::HEADER, self::VERSION);
        }

        return $response;
    }

    public static function versioned(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), self::ROUTE_PREFIX);
    }
}
