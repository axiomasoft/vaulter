<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use Vaulter\Attributes\Internal;

/**
 * The bearer share-token hashing primitives (hardening). The raw token
 * is hashed with sha256 to a char(64) the DB stores as token_hash; the raw token
 * is never persisted. The prefix is a short, non-secret leading slice kept for
 * owner-side UX lookup. Comparison is timing-safe (hash_equals) so a token can
 * never be probed character-by-character via response timing.
 *
 * Technical axis — no domain knowledge, no model references.
 */
#[Internal]
final class ShareToken
{
    /**
     * The sha256 hex digest of a raw token — exactly the char(64) stored as
     * token_hash. Deterministic, so a presented token can be hashed and matched
     * against the stored hash without ever storing the token itself.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The non-secret leading slice of a raw token kept as token_prefix for
     * owner-side UX lookup (it reveals nothing usable — the secret is the whole
     * token, matched only via its full hash).
     */
    public static function prefix(string $token, int $length): string
    {
        return mb_substr($token, 0, max($length, 1));
    }

    /**
     * Timing-safe equality of two token hashes (hash_equals). Used so a near-miss
     * token can never be distinguished from a far-miss by comparing wall-clock
     * response time — a side-channel that a naive === would open.
     */
    public static function hashesMatch(string $expectedHash, string $candidateHash): bool
    {
        return hash_equals($expectedHash, $candidateHash);
    }
}
