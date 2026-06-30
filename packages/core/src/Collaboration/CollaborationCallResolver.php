<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;
use Vaulter\Support\Config;

/**
 * Authenticates a server-to-server collaboration webhook call (M4b) — the calls the
 * Node Hocuspocus hub's onAuthenticate / onStoreDocument make BACK into Vaulter. A
 * call is accepted by EITHER:
 *   - the shared webhook secret (config(vaulter.collaboration.webhook_secret)),
 *     compared timing-safely; or
 *   - a valid signed room JWT (HS256 with the hocuspocus secret), signature + exp
 *     verified by firebase/php-jwt.
 *
 * The endpoints are NEVER open: when neither secret is configured every call is
 * rejected (null) rather than waved through. A tampered or expired JWT is rejected.
 */
final readonly class CollaborationCallResolver
{
    /**
     * Resolve the call's authenticated identity, or null when it cannot be
     * authenticated (missing/wrong secret AND missing/tampered/expired JWT, or no
     * verifier configured at all). The caller turns null into a 401.
     */
    public function resolve(?string $presentedSecret, ?string $bearerToken): ?CollaborationCall
    {
        $sharedSecret = Config::collaborationWebhookSecret();

        if ($sharedSecret !== null
            && is_string($presentedSecret)
            && hash_equals($sharedSecret, $presentedSecret)
        ) {
            return CollaborationCall::bySecret();
        }

        return $this->resolveJwt($bearerToken);
    }

    /**
     * Verify a presented JWT against the hocuspocus signing secret and lift its
     * {room, sub, readOnly} claims. Null on any failure (no secret, no token, bad
     * signature, expired, malformed). firebase/php-jwt enforces exp itself.
     */
    private function resolveJwt(?string $bearerToken): ?CollaborationCall
    {
        if (!is_string($bearerToken) || $bearerToken === '' || !class_exists(JWT::class)) {
            return null;
        }

        $secret = Config::collaborationJwtSecret();

        if ($secret === null) {
            return null;
        }

        try {
            $decoded = JWT::decode($bearerToken, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }

        $room = $decoded->room ?? null;

        if (!is_string($room) || $room === '') {
            return null;
        }

        $subject = isset($decoded->sub) && is_scalar($decoded->sub) ? (string) $decoded->sub : null;
        $readOnly = isset($decoded->readOnly) && (bool) $decoded->readOnly;

        return CollaborationCall::byJwt(room: $room, subject: $subject, readOnly: $readOnly);
    }
}
