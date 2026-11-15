<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Data\RoomRef;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Support\Identity;

/**
 * The typed reader of the `vaulter-collaboration` configuration root: the transport, the hub (URL, JWT key set and issuer, webhook secret,
 * token lifetime and clock skew), the control channel of revocations, the largest Yjs state a store may write, the protocol 2 limits, the compaction triggers and lease,
 * the drain deadline, flag store and append window, and the retention of closed generations. The room channel prefix
 * is the documents package's ({@see DocumentsConfig::channelPrefix()}): the comment events of a document broadcast on
 * the same channels.
 */
#[Api]
final readonly class CollaborationConfig
{
    /** The hub's total retry deadline for one call (seconds), network time included: no retry arrives later. */
    public const int RETRY_HORIZON_SECONDS = 10;

    #[Internal]
    public function __construct(private Repository $config, private DocumentsConfig $documents) {}

    public function channelPrefix(): string
    {
        return $this->documents->channelPrefix();
    }

    /**
     * The room of one generation of a document: "<prefix>.<driveId>.<nodeId>.<epoch>".
     * The epoch is part of the name, so a room opened before a restore can never be
     * confused with the one opened after it.
     */
    public function room(mixed $driveId, mixed $nodeId, int $epoch): string
    {
        return $this->channelPrefix().'.'.Identity::stringify($driveId).'.'.Identity::stringify($nodeId).'.'.$epoch;
    }

    /** The parts of a room name, or null when it is not exactly one this installation issues. */
    public function parseRoom(string $room): ?RoomRef
    {
        $prefix = $this->channelPrefix().'.';
        if (!str_starts_with($room, $prefix)) {
            return null;
        }

        $parts = explode('.', substr($room, strlen($prefix)));
        if (count($parts) !== 3) {
            return null;
        }

        [$driveId, $nodeId, $epoch] = $parts;
        if ($driveId === '' || $nodeId === '' || !ctype_digit($epoch) || ($epoch !== '0' && str_starts_with($epoch, '0'))) {
            return null;
        }

        $generation = filter_var($epoch, FILTER_VALIDATE_INT);

        return $generation === false ? null : new RoomRef(driveId: $driveId, nodeId: $nodeId, epoch: $generation);
    }

    /** The largest binary Yjs state a collaboration store may write. */
    public function maxStateBytes(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.max_state_bytes', default: 16777216);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 16777216;
    }

    /**
     * When an edit counts as saved under protocol 2: `buffered` (the default) or `durable`.
     *
     * @throws InvalidConfigurationException for any other value
     */
    public function mode(): string
    {
        $value = $this->config->get(key: 'vaulter-collaboration.mode', default: 'buffered');
        if (!in_array($value, ['buffered', 'durable'], true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.mode must be "buffered" or "durable".');
        }

        return $value;
    }

    /** The largest decoded Yjs update one append may carry. */
    public function updateBytes(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.limits.update_bytes', default: 1048576);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1048576;
    }

    /** The largest append request body, checked by its Content-Length before the body is read. */
    public function requestBytes(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.limits.request_bytes', default: 2097152);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 2097152;
    }

    /** The largest checkpoint request body: a base64 state of max_state_bytes and request_bytes for the projection and the rest. */
    public function checkpointRequestBytes(): int
    {
        return 4 * intdiv($this->maxStateBytes() + 2, 3) + $this->requestBytes();
    }

    /** The uncheckpointed updates of a document generation that make it due for compaction. */
    public function compactionTriggerUpdates(): int
    {
        return $this->positive('vaulter-collaboration.compaction.trigger_updates', 500);
    }

    /** The bytes of uncheckpointed updates that make a document generation due for compaction. */
    public function compactionTriggerBytes(): int
    {
        return $this->positive('vaulter-collaboration.compaction.trigger_bytes', 1048576);
    }

    /** The age in seconds of the oldest uncheckpointed update that makes a document generation due for compaction. */
    public function compactionTriggerAge(): int
    {
        return $this->positive('vaulter-collaboration.compaction.trigger_age', 600);
    }

    /** How many seconds a compaction lease keeps a document from other hubs. */
    public function compactionLeaseTtl(): int
    {
        return $this->positive('vaulter-collaboration.compaction.lease_ttl', 60);
    }

    /** How many seconds a restore, REST replace, content read or export waits for the hub to drain a pending document. */
    public function restoreDeadline(): int
    {
        return $this->positive('vaulter-collaboration.drain.restore_deadline', 10);
    }

    /** The cache store of the draining flag; null for the default store. */
    public function drainCacheStore(): ?string
    {
        $value = $this->config->get('vaulter-collaboration.drain.cache_store');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The longest an append transaction lasts (seconds): a drain decides no earlier than this after it set the flag. */
    public function drainMaxAppendTx(): int
    {
        return $this->positive('vaulter-collaboration.drain.max_append_tx', 5);
    }

    /** Days the rows of a closed generation stay once no token or retry of it can arrive any more. */
    public function retiredGenerationDays(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.retention.retired_generation_days', default: 7);

        return is_numeric($value) ? max((int) $value, 0) : 7;
    }

    /**
     * How long after it closed a generation's rows may go: the lifetime of the last token issued for it, the
     * hub's retry horizon, then the retention window. Until then a late retry still finds its receipt.
     */
    public function retiredGenerationGraceSeconds(): int
    {
        return $this->hubTokenTtl() + self::RETRY_HORIZON_SECONDS + $this->retiredGenerationDays() * 86400;
    }

    public function hubWebhookSecret(): ?string
    {
        $value = $this->config->get('vaulter-collaboration.hub.webhook_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The audience every room token names: the hub, nothing else. */
    public const string JWT_AUDIENCE = 'vaulter-collaboration-hub';

    /** The wire protocol of a room token: the binding protocol `yjs` at revision 2. */
    public const string JWT_PROTOCOL = 'yjs/2';

    /** The shortest HS256 key (bytes) of the key set, the previous ones included. */
    public const int MIN_JWT_KEY_BYTES = 32;

    /** The widest clock skew a room token's time claims tolerate (seconds). */
    public const int MAX_CLOCK_SKEW = 30;

    /**
     * The local key map `kid => secret` that signs and verifies room tokens; malformed entries are left out here and
     * reported by {@see self::hubJwtKeysViolation()}. The hub never gets it.
     *
     * @return array<string, string>
     */
    public function hubJwtKeys(): array
    {
        $keys = [];
        foreach ($this->jwtKeyEntries() as $kid => $secret) {
            if ((string) $kid !== '' && is_string($secret) && $secret !== '') {
                $keys[(string) $kid] = $secret;
            }
        }

        return $keys;
    }

    /** The kid of the key that signs new and renewed room tokens. */
    public function hubJwtActiveKid(): ?string
    {
        $value = $this->config->get('vaulter-collaboration.hub.jwt_active_kid');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The secret of the active kid, or null while the key set does not name it. */
    public function hubJwtSigningKey(): ?string
    {
        $kid = $this->hubJwtActiveKid();

        return $kid === null ? null : ($this->hubJwtKeys()[$kid] ?? null);
    }

    /** The exact `iss` of a room token: `hub.issuer`, else the configured `app.url` (never the request host). */
    public function hubIssuer(): ?string
    {
        foreach (['vaulter-collaboration.hub.issuer', 'app.url'] as $key) {
            $value = $this->config->get($key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The raw entries of hub.jwt_keys: the array itself, or the comma-separated "kid:secret" pairs of the environment
     * string (a pair without a secret or a repeated kid becomes an invalid entry, reported by the violation).
     *
     * @return array<int|string, mixed>
     */
    private function jwtKeyEntries(): array
    {
        $value = $this->config->get('vaulter-collaboration.hub.jwt_keys');
        if (is_array($value)) {
            return $value;
        }
        $entries = [];
        foreach (is_string($value) ? explode(',', $value) : [] as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            $parts = explode(':', trim($pair), 2);
            $kid = $parts[0];
            $entries[$kid] = array_key_exists($kid, $entries) ? null : ($parts[1] ?? null);
        }

        return $entries;
    }

    /** Whether any room-token key setting exists at all (a fully unset module is reported by the doctor and the invariant). */
    private function hasJwtKeySettings(): bool
    {
        return $this->jwtKeyEntries() !== [] || $this->hubJwtActiveKid() !== null;
    }

    /**
     * Why the key set cannot sign and verify room tokens, or null when it can. A reason names no secret and no kid:
     * the set is nonempty, every entry has a nonempty kid and a string secret of at least 32 bytes, the active kid is
     * in the set and no key — old ones included — equals the webhook secret.
     */
    public function hubJwtKeysViolation(): ?string
    {
        $value = $this->jwtKeyEntries();
        if ($value === []) {
            return 'hub.jwt_keys is empty';
        }
        $webhook = $this->hubWebhookSecret();
        foreach ($value as $kid => $secret) {
            if ((string) $kid === '' || (string) $kid !== trim((string) $kid) || !is_string($secret)) {
                return 'hub.jwt_keys has an entry without a clean kid or a string secret';
            }
            if (strlen($secret) < self::MIN_JWT_KEY_BYTES) {
                return 'hub.jwt_keys has a key shorter than '.self::MIN_JWT_KEY_BYTES.' bytes';
            }
            if ($webhook !== null && hash_equals($webhook, $secret)) {
                return 'hub.jwt_keys has a key equal to hub.webhook_secret';
            }
        }
        $active = $this->hubJwtActiveKid();
        if ($active === null || !array_key_exists($active, $value)) {
            return 'hub.jwt_active_kid does not name a key of hub.jwt_keys';
        }

        return null;
    }

    public function hubUrl(): ?string
    {
        $value = $this->config->get('vaulter-collaboration.hub.url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hubTimestampTolerance(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.hub.timestamp_tolerance', default: 300);

        $seconds = is_numeric($value) ? (int) $value : 300;

        return max($seconds, 1);
    }

    /** The longest a room token may live (seconds): bounds the exposure of a lost control message by it. */
    public const int MAX_TOKEN_TTL = 300;

    /** The control transports: Redis pub/sub, HTTP to every hub's management port, or nothing. */
    public const array CONTROL_TRANSPORTS = ['redis', 'http', 'none'];

    /**
     * The lifetime of a room token. A hub renews it before it expires, and a renewal checks the rights again, so a
     * token that missed a revocation lives at most this long.
     *
     * @throws InvalidConfigurationException outside 1..300 seconds
     */
    public function hubTokenTtl(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.hub.token_ttl', default: self::MAX_TOKEN_TTL);
        $seconds = is_numeric($value) ? (int) $value : self::MAX_TOKEN_TTL;

        if ($seconds < 1 || $seconds > self::MAX_TOKEN_TTL) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.hub.token_ttl must be between 1 and '.self::MAX_TOKEN_TTL.' seconds.');
        }

        return $seconds;
    }

    /**
     * The clock difference tolerated on the exp of a room token (seconds), by the backend here and by the hub.
     *
     * @throws InvalidConfigurationException outside 0..30 seconds
     */
    public function hubClockSkew(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.hub.clock_skew', default: 30);
        $seconds = is_numeric($value) ? (int) $value : 30;

        if ($seconds < 0 || $seconds > self::MAX_CLOCK_SKEW) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.hub.clock_skew must be between 0 and '.self::MAX_CLOCK_SKEW.' seconds.');
        }

        return $seconds;
    }

    /**
     * How a change of effective permissions reaches the hubs: `redis`, `http` or `none`.
     *
     * @throws InvalidConfigurationException for any other value
     */
    public function controlTransport(): string
    {
        $value = $this->config->get(key: 'vaulter-collaboration.control.transport', default: 'none');
        if (!is_string($value) || !in_array($value, self::CONTROL_TRANSPORTS, true)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.control.transport must be "redis", "http" or "none".');
        }

        return $value;
    }

    /** The Redis connection the `redis` control transport publishes on. */
    public function controlRedisConnection(): string
    {
        $value = $this->config->get('vaulter-collaboration.control.redis.connection');

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    /**
     * The management base URLs of the hubs the `http` control transport posts to (a list, or one comma-separated string).
     *
     * @return list<string>
     */
    public function controlHttpUrls(): array
    {
        $value = $this->config->get('vaulter-collaboration.control.http.urls', []);
        $urls = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);

        return array_values(array_filter(
            array_map(static fn (mixed $url): string => is_string($url) ? rtrim(trim($url), '/') : '', $urls),
            static fn (string $url): bool => $url !== '',
        ));
    }

    /** How long one control POST may take (seconds). */
    public function controlHttpTimeout(): int
    {
        return $this->positive('vaulter-collaboration.control.http.timeout', 2);
    }

    /**
     * The room-token keys and the webhook secret are two different trust boundaries: the hub owns the webhook secret,
     * so a JWT key equal to it would let the hub sign any room token (mode rw, any user). A malformed key set — empty,
     * a short key, a missing active kid, a reused webhook secret — is refused at boot in EVERY environment, because
     * core's ConfigValidator only warns outside production and a copied dev value tends to reach production unchanged.
     * A module with no key settings at all is left to the doctor and the `collaboration.secrets` invariant.
     *
     * @throws InvalidConfigurationException when the key set is malformed or reuses the webhook secret
     */
    public function assertDistinctHubSecrets(): void
    {
        $violation = $this->hasJwtKeySettings() ? $this->hubJwtKeysViolation() : null;

        if ($violation !== null) {
            throw new InvalidConfigurationException(
                '[Vaulter] vaulter-collaboration.hub.jwt_keys is unusable: '.$violation.'. '
                .'Why: the hub holds the webhook secret, so a key equal to it could sign a room token for any user; a short key is brute-forceable. '
                .'Fix: set VAULTER_COLLAB_JWT_KEYS ("kid:secret", comma-separated, each secret at least 32 bytes and different from VAULTER_COLLAB_WEBHOOK_SECRET) and VAULTER_COLLAB_JWT_ACTIVE_KID to one of its kids.',
            );
        }
    }

    /** A positive integer setting; anything else reads as the default. */
    private function positive(string $key, int $default): int
    {
        $value = $this->config->get(key: $key, default: $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
