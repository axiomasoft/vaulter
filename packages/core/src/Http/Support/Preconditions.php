<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Illuminate\Http\Request;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Exceptions\Nodes\StaleNodeException;

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

    /**
     * The entity tags of a required If-Match, as sent (weak ones keep their W/ prefix).
     * Missing → 428; `*` → 428 too, because it matches any representation.
     *
     * @return list<string>
     */
    public static function requireIfMatch(Request $request): array
    {
        $header = $request->headers->get(self::IF_MATCH);
        if ($header === null || trim($header) === '') {
            throw PreconditionRequiredException::missing(self::IF_MATCH);
        }
        $tags = self::tags($header);
        if (in_array('*', $tags, true)) {
            throw PreconditionRequiredException::wildcard();
        }

        return $tags;
    }

    /**
     * Compare If-Match with the caller's current node tag (strong comparison: a weak
     * tag never matches) and return the sequence to expect in the mutation.
     *
     * @throws StaleNodeException when no listed tag is the current one (412)
     */
    public static function matchNode(Request $request, string $currentEtag, NodeSnapshot $node): int
    {
        if (!self::strongMatch(self::requireIfMatch($request), $currentEtag)) {
            throw StaleNodeException::forNode($node->id);
        }

        return $node->sequence;
    }

    /**
     * The same for document content; the owning package supplies its own 412.
     */
    public static function matchDocument(Request $request, string $currentEtag, int $stateVersion, Throwable $mismatch): int
    {
        if (!self::strongMatch(self::requireIfMatch($request), $currentEtag)) {
            throw $mismatch;
        }

        return $stateVersion;
    }

    /** Whether If-None-Match lets the server answer 304 (weak comparison). */
    public static function notModified(Request $request, string $currentEtag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if ($header === null || trim($header) === '') {
            return false;
        }
        foreach (self::tags($header) as $tag) {
            if ($tag === '*' || self::opaque($tag) === self::opaque($currentEtag)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tags */
    private static function strongMatch(array $tags, string $currentEtag): bool
    {
        foreach ($tags as $tag) {
            if (!str_starts_with($tag, 'W/') && hash_equals($currentEtag, $tag)) {
                return true;
            }
        }

        return false;
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
