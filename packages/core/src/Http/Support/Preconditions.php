<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Exceptions\Http\WeakEtagNotAllowedException;

/**
 * Conditional requests of the versioned API (RFC 9110).
 *
 * Every method here runs only after the controller has found the resource through
 * its operations, i.e. after authentication, tenant scoping and authorization: a
 * caller who may not see a node gets its 404 before any 304, 412 or 428, and a 412
 * never reveals the current tag.
 *
 * Matching the header is not the lost-update guard by itself: the caller passes the
 * returned sequence or state version to the operation, whose compare-and-set in the
 * writing transaction refuses a change that slipped in after the comparison.
 */
#[Internal]
final class Preconditions
{
    private const string IF_MATCH = 'If-Match';

    public static function ifMatch(Request $request): ?string
    {
        return $request->headers->get(self::IF_MATCH) ?? $request->headers->get('X-Vaulter-If-Match');
    }

    /**
     * The strong entity tags of a required If-Match.
     * Missing → 428; `*` → 428 too, because it matches any representation.
     *
     * @return list<string>
     */
    public static function requireIfMatch(Request $request): array
    {
        $header = self::ifMatch($request);
        if ($header === null || trim($header) === '') {
            throw PreconditionRequiredException::missing(self::IF_MATCH);
        }
        $tags = self::tags($header);
        if (array_any($tags, static fn (string $tag): bool => str_starts_with($tag, 'W/'))) {
            throw new WeakEtagNotAllowedException('[Vaulter] If-Match requires a strong entity tag.');
        }
        if (in_array('*', $tags, true)) {
            throw PreconditionRequiredException::wildcard();
        }

        return $tags;
    }

    /**
     * Whether a required If-Match holds the caller's current entity tag (strong comparison: a weak tag never
     * matches). Missing → 428; `*` → 428 too. The owner raises its own 412 when this is false and passes the
     * sequence or state version it read to the operation, whose compare-and-set refuses a change that slipped in.
     */
    public static function matches(Request $request, string $currentEtag): bool
    {
        return in_array($currentEtag, self::requireIfMatch($request), true);
    }

    /** Whether If-None-Match lets the server answer 304 (weak comparison). */
    public static function notModified(Request $request, string $currentEtag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if ($header === null || trim($header) === '') {
            return false;
        }

        return array_any(self::tags($header), fn (string $tag): bool => $tag === '*' || self::opaque($tag) === self::opaque($currentEtag));
    }

    /**
     * Split a list header into entity tags. Entity tags cannot contain a double quote,
     * so a comma inside quotes stays part of its tag.
     *
     * @return list<string>
     */
    private static function tags(string $header): array
    {
        preg_match_all('/\s*(\*|(?:W\/)?"[^"]*")\s*(?:,|$)/', $header, $matches);

        return $matches[1];
    }

    private static function opaque(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
