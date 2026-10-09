<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Authenticates a server-to-server call the Node Hocuspocus hub makes back into
 * Vaulter (collab/authenticate, collab/load, collab/store). Two independent barriers,
 * both required:
 *
 *   - the room JWT the client got from collaborationSession() and the hub relays
 *     (HS256, hub.jwt_secret): it names the user, the room and the mode, so the hub can
 *     never act for anyone, anywhere or with more rights than the token carries;
 *   - an HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by hub.webhook_secret,
 *     with a bounded timestamp and a replay cache in the application's Cache store
 *     (shared by every instance): a captured request cannot be replayed or altered.
 *     The hub puts a random nonce in every body, so two genuine calls with the same
 *     payload in the same second never share a signature.
 *
 * The endpoints are never open: without either secret every call is rejected.
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
     * tolerance window, so a replayed signature is refused.
     */
    private const string NONCE_CACHE_PREFIX = 'vaulter:collaboration:webhook-nonce:';

    public function __construct(
        private DocumentsConfig $documentsConfig,
        private WebhookSigner $signer = new WebhookSigner,
    ) {}

    /**
     * The verified call, or null when the token is missing, tampered, expired or does
     * not describe one room exactly, or the body signature does not verify (missing,
     * stale, replayed, wrong). The caller turns null into a 401.
     */
    public function resolve(
        ?string $bearerToken,
        ?string $presentedTimestamp,
        ?string $presentedSignature,
        string $rawBody,
    ): ?CollaborationCall {
        $call = $this->resolveJwt($bearerToken);

        if (!$call instanceof CollaborationCall) {
            return null;
        }

        if (!$this->verifySignature($presentedTimestamp, $presentedSignature, $rawBody)) {
            return null;
        }

        return $call;
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
        $secret = $this->documentsConfig->hubWebhookSecret();

        if ($secret === null
            || !is_string($presentedTimestamp) || $presentedTimestamp === ''
            || !is_string($presentedSignature) || $presentedSignature === ''
            || !ctype_digit($presentedTimestamp)
        ) {
            return false;
        }

        $tolerance = $this->documentsConfig->hubTimestampTolerance();
        $timestamp = (int) $presentedTimestamp;

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = $this->signer->sign(body: $rawBody, secret: $secret, timestamp: $timestamp);

        if (!hash_equals($expected, $presentedSignature)) {
            // Invalid signature for this exact rawBody — do NOT burn the nonce slot,
            // otherwise a tampered/mutated delivery under a captured signature would
            // permanently block a subsequent legitimate delivery of the original body.
            return false;
        }

        // The signature stays acceptable until $timestamp + $tolerance (a future timestamp
        // stretches that to 2 × tolerance from now), so the nonce must live that long.
        $remaining = max(1, $timestamp + $tolerance - time() + 1);

        if (Cache::add(self::NONCE_CACHE_PREFIX.$presentedSignature, true, $remaining) === false) {
            // The exact same (now verified-valid) signature was already accepted
            // while it was still within its tolerance window — a replayed request.
            return false;
        }

        return true;
    }

    /**
     * Verify the room JWT (signature and exp, by firebase/php-jwt) and lift its claims.
     * The room must parse, and its drive/node/epoch must repeat the token's own claims,
     * so no claim can be read two ways. Null on any failure.
     */
    private function resolveJwt(?string $bearerToken): ?CollaborationCall
    {
        if (!is_string($bearerToken) || $bearerToken === '' || !class_exists(JWT::class)) {
            return null;
        }

        $secret = $this->documentsConfig->hubJwtSecret();

        if ($secret === null) {
            return null;
        }

        try {
            $claims = (array) JWT::decode($bearerToken, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }

        $room = $claims['room'] ?? null;
        $subject = $claims['sub'] ?? null;
        $mode = $claims['mode'] ?? null;
        $epoch = $claims['epoch'] ?? null;
        $schemaVersion = $claims['schema_version'] ?? null;
        $tenant = $claims['tenant'] ?? null;

        if (!is_string($room) || !is_string($subject) || $subject === '' || !in_array($mode, ['rw', 'ro'], true)
            || !is_int($epoch) || !is_int($schemaVersion) || ($tenant !== null && !is_string($tenant))) {
            return null;
        }

        $ref = $this->documentsConfig->parseRoom($room);

        if ($ref === null || $ref->epoch !== $epoch
            || ($claims['node'] ?? null) !== $ref->nodeId || ($claims['drive'] ?? null) !== $ref->driveId) {
            return null;
        }

        return new CollaborationCall(
            room: $room,
            ref: $ref,
            subject: $subject,
            mode: $mode,
            schemaVersion: $schemaVersion,
            tenant: $tenant,
        );
    }
}
