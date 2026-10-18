<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Revocation\PermissionVersion;

/**
 * Authenticates a server-to-server call the Node Hocuspocus hub makes back into
 * Vaulter (authenticate, load, append, renew). Two independent barriers,
 * both required:
 *
 *   - the room JWT the client got from collaborationSession() and the hub relays
 *     (HS256, hub.jwt_secret): it names the user, the room and the scope, so the hub can
 *     never act for anyone, anywhere or with more rights than the token carries;
 *   - an HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by hub.webhook_secret,
 *     with a bounded timestamp and a replay cache in the application's Cache store
 *     (shared by every instance): a captured request cannot be replayed or altered.
 *     The hub puts a random nonce in every body, so two genuine calls with the same
 *     payload in the same second never share a signature.
 *
 * The endpoints are never open: without either secret every call is rejected.
 */
#[Internal]
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
        private CollaborationConfig $config,
    ) {}

    /**
     * The hub's signature of a request body: lowercase hex HMAC-SHA256 of
     * "{timestamp}.{rawBody}" keyed by the hub webhook secret. This is the hub's own
     * contract, deliberately not the Standard Webhooks signature of outbound deliveries,
     * which binds a webhook id the hub does not send.
     */
    public static function signature(string $rawBody, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

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
        $call = $this->verifyToken($bearerToken);

        if (!$call instanceof CollaborationCall) {
            return null;
        }

        if (!$this->verifySignature($presentedTimestamp, $presentedSignature, $rawBody)) {
            return null;
        }

        return $call;
    }

    /**
     * Whether a call the hub makes for itself — its compaction worker, not a room connection, so without a room JWT —
     * carries a valid body signature (the same check, timestamp tolerance and replay cache as a room call's).
     */
    public function verifyHub(?string $presentedTimestamp, ?string $presentedSignature, string $rawBody): bool
    {
        return $this->verifySignature($presentedTimestamp, $presentedSignature, $rawBody);
    }

    /**
     * Verify the body's HMAC-SHA256 signature: the timestamp must be a well-formed
     * integer within ±tolerance of now, the signature must not have been seen before
     * within that same window (replay), and it must equal the timing-safe HMAC of
     * "{timestamp}.{rawBody}" keyed by the webhook secret ({@see self::signature()}), so
     * Node and PHP MUST agree byte-for-byte on $rawBody (the exact HTTP request body, not
     * a re-encoded copy).
     */
    private function verifySignature(?string $presentedTimestamp, ?string $presentedSignature, string $rawBody): bool
    {
        $secret = $this->config->hubWebhookSecret();

        if ($secret === null
            || !is_string($presentedTimestamp) || $presentedTimestamp === ''
            || !is_string($presentedSignature) || $presentedSignature === ''
            || !ctype_digit($presentedTimestamp)
        ) {
            return false;
        }

        $tolerance = $this->config->hubTimestampTolerance();
        $timestamp = (int) $presentedTimestamp;

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = self::signature(rawBody: $rawBody, secret: $secret, timestamp: $timestamp);

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
     * Verify a room JWT on its own (signature and exp, by firebase/php-jwt, with `hub.clock_skew` of leeway) and lift
     * its claims: what a batch recheck does for each connection the signed body names. The room must parse, and its
     * drive/document/generation must repeat the token's own claims, so no claim can be read two ways. Null on any
     * failure.
     */
    public function verifyToken(?string $bearerToken): ?CollaborationCall
    {
        if (!is_string($bearerToken) || $bearerToken === '') {
            return null;
        }

        $secret = $this->config->hubJwtSecret();

        if ($secret === null) {
            return null;
        }

        // The application clock, as the tokens are minted by it, and the clock skew both sides tolerate.
        [$leeway, $timestamp] = [JWT::$leeway, JWT::$timestamp];
        try {
            JWT::$leeway = $this->config->hubClockSkew();
            JWT::$timestamp = Carbon::now()->getTimestamp();
            $claims = (array) JWT::decode($bearerToken, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        } finally {
            [JWT::$leeway, JWT::$timestamp] = [$leeway, $timestamp];
        }

        $room = $claims['room'] ?? null;
        $subject = $claims['sub'] ?? null;
        $scope = $claims['scope'] ?? null;
        $generation = $claims['generation'] ?? null;
        $schemaVersion = $claims['schema_version'] ?? null;
        $tenant = $claims['tenant'] ?? null;
        $jti = $claims['jti'] ?? null;
        $exp = $claims['exp'] ?? null;
        $version = PermissionVersion::fromClaim($claims['permissions_version'] ?? null);

        if (!is_string($room) || !is_string($subject) || $subject === ''
            || !in_array($scope, [CollaborationCall::SCOPE_READ, CollaborationCall::SCOPE_WRITE], true)
            || !is_int($generation) || !is_int($schemaVersion) || ($tenant !== null && !is_string($tenant))
            || !is_string($jti) || $jti === '' || !is_int($exp) || !$version instanceof PermissionVersion) {
            return null;
        }

        $ref = $this->config->parseRoom($room);

        if ($ref === null || $ref->epoch !== $generation
            || ($claims['document_id'] ?? null) !== $ref->nodeId || ($claims['drive_id'] ?? null) !== $ref->driveId) {
            return null;
        }

        return new CollaborationCall(
            room: $room,
            ref: $ref,
            subject: $subject,
            mode: $scope === CollaborationCall::SCOPE_WRITE ? 'rw' : 'ro',
            schemaVersion: $schemaVersion,
            tenant: $tenant,
            permissionsVersion: $version,
            jti: $jti,
            expiresAt: $exp,
        );
    }
}
