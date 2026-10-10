<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every Vaulter request one correlation id: a well-formed incoming X-Request-Id is
 * kept so a host or gateway can trace a call end to end, anything else is replaced by a
 * fresh ULID. The id travels with the operation (activity, outbox) and is echoed on every
 * response, errors and 304 included.
 */
final class AssignCorrelationId
{
    public const string ATTRIBUTE = 'vaulter.request_id';

    public const string HEADER = 'X-Request-Id';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $id = self::accepted($request->headers->get(self::HEADER)) ?? (string) Str::ulid();
        $request->attributes->set(self::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The id the request already carries, once assigned. */
    public static function of(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function accepted(?string $incoming): ?string
    {
        return $incoming !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $incoming) === 1 ? $incoming : null;
    }
}
