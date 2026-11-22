<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One cache policy for the versioned API, decided here rather than in each controller:
 *
 *  - a single resource with a validator (ETag, or the 304 answering it) is personal and
 *    revalidated on every use: `private, no-cache, no-transform`;
 *  - bytes, signed redirects, lists, mutations, secrets and errors are never stored;
 *  - public share responses are never stored and never leak the token-bearing URL
 *    through the Referer of a page they open.
 *
 * A shared cache must not hand one caller's representation (its abilities, its starred
 * flag) to another, and a revoked caller must not be served from a cache.
 */
#[Internal]
final class ApplyResponseCachePolicy
{
    private const string PUBLIC_SHARE_ROUTES = 'vaulter.v1.public-shares.';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!AddApiVersionHeader::versioned($request)) {
            return $response;
        }

        $etag = $response->headers->get('ETag');
        $strong = $etag !== null && preg_match('/^"[^"]*"$/D', $etag) === 1;
        $revalidate = $response->getStatusCode() === 304 || ($request->isMethodSafe() && $response->isSuccessful() && $etag !== null);
        if ($strong) {
            $response->headers->set('X-Vaulter-ETag', $etag);
        }
        if ($strong || $revalidate) {
            $vary = array_filter($response->getVary(), is_string(...));
            $response->setVary(array_values(array_unique(['Authorization', 'Accept', 'Cookie', ...$vary])));
        }

        if (str_starts_with((string) $request->route()?->getName(), self::PUBLIC_SHARE_ROUTES)) {
            $response->headers->set('Cache-Control', $strong ? 'private, no-store, no-transform' : 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        if (self::carriesContent($response)) {
            $response->headers->set('Cache-Control', $strong ? 'private, no-store, no-transform' : 'private, no-store');

            return $response;
        }

        // A mutation answers with the new tag too, but its response is never stored.
        if ($revalidate) {
            $response->headers->set('Cache-Control', 'private, no-cache, no-transform');

            return $response;
        }

        $response->headers->set('Cache-Control', $request->isMethodSafe() ? 'private, no-store' : 'no-store');
        if ($strong) {
            $response->headers->addCacheControlDirective('no-transform');
        }

        return $response;
    }

    private static function carriesContent(Response $response): bool
    {
        return $response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response instanceof RedirectResponse;
    }
}
