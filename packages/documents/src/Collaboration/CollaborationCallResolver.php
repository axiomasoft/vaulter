<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Vaulter\Support\Config;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Authenticates a server-to-server collaboration webhook call (M4b) — the calls the
 * Node Hocuspocus hub's onAuthenticate / onStoreDocument make BACK into Vaulter. A
 * call's IDENTITY is accepted by EITHER:
 *   - the shared webhook secret (config(vaulter.collaboration.webhook_secret)),
 *     compared timing-safely; or
 *   - a valid signed room JWT (HS256 with the hocuspocus secret), signature + exp
 *     verified by firebase/php-jwt.
 *
 * On top of identity, VLT-REMED P3.2 (F-P1.6-02, N2) requires EVERY call — regardless
 * of which identity path it took — to carry a valid HMAC-SHA256 body signature: this
 * closes the gap where a captured secret/JWT let an attacker replay or tamper with the
 * `yjs_state`/`node_id` payload indefinitely. The signature is an ADDITIONAL barrier,
 * never a substitute for identity: both must pass.
 *
 * The endpoints are NEVER open: when neither secret is configured every call is
 * rejected (null) rather than waved through. A tampered/expired JWT, an unsigned body,
 * a stale timestamp or a replayed signature are all rejected.
 */
final readonly class CollaborationCallResolver
{
    /**
     * The header the raw signed timestamp (unix seconds) is presented in.
     */
    public const string TIMESTAMP_HEADER = 'X-Vaulter-Collab-Timestamp';

    /**
     * The header the HMAC-SHA256 signature of "{timestamp}.{rawBody}" is presented in.
     */
    public const string SIGNATURE_HEADER = 'X-Vaulter-Collab-Signature';

    /**
     * The cache-key prefix a presented signature is remembered under for the
     * tolerance window, so a replayed signature is refused (F-P1.6-02 anti-replay).
     */
    private const string NONCE_CACHE_PREFIX = 'vaulter:collaboration:webhook-nonce:';

    public function __construct(
        private WebhookSigner $signer = new WebhookSigner,
    ) {}

    /**
     * Resolve the call's authenticated identity, or null when it cannot be
     * authenticated: missing/wrong secret AND missing/tampered/expired JWT, no
     * verifier configured at all, OR the body's HMAC signature does not verify
     * (missing, stale timestamp, replayed nonce, wrong signature). The caller turns
     * null into a 401.
     */
    public function resolve(
        ?string $presentedSecret,
        ?string $bearerToken,
        ?string $presentedTimestamp,
        ?string $presentedSignature,
        string $rawBody,
    ): ?CollaborationCall {
        $call = $this->resolveIdentity($presentedSecret, $bearerToken);

        if (!$call instanceof CollaborationCall) {
            return null;
        }

        if (!$this->verifySignature($presentedTimestamp, $presentedSignature, $rawBody)) {
            return null;
        }

        return $call;
    }

    private function resolveIdentity(?string $presentedSecret, ?string $bearerToken): ?CollaborationCall
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
     * Verify the body's HMAC-SHA256 signature: the timestamp must be a well-formed
     * integer within ±tolerance of now, the signature must not have been seen before
     * within that same window (replay), and it must equal the timing-safe HMAC of
     * "{timestamp}.{rawBody}" keyed by the webhook secret — the SAME canonical string
     * {@see WebhookSigner} uses for outbound deliveries, so Node and PHP MUST agree
     * byte-for-byte on $rawBody (the exact HTTP request body, not a re-encoded copy).
     */
    private function verifySignature(?string $presentedTimestamp, ?string $presentedSignature, string $rawBody): bool
    {
        $secret = Config::collaborationWebhookSecret();

        if ($secret === null
            || !is_string($presentedTimestamp) || $presentedTimestamp === ''
            || !is_string($presentedSignature) || $presentedSignature === ''
            || !ctype_digit($presentedTimestamp)
        ) {
            return false;
        }

        $tolerance = Config::collaborationWebhookTimestampTolerance();
        $timestamp = (int) $presentedTimestamp;

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        if (Cache::add(self::NONCE_CACHE_PREFIX.$presentedSignature, true, $tolerance) === false) {
            // The exact same signature was already accepted within the tolerance
            // window — a replayed request, not a fresh one.
            return false;
        }

        $expected = $this->signer->sign(body: $rawBody, secret: $secret, timestamp: $timestamp);

        return hash_equals($expected, $presentedSignature);
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
