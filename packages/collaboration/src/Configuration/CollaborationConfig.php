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
 * The typed reader of the `vaulter-collaboration` configuration root: the transport, the hub (URL, secrets,
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
     * How long after it closed a generation's rows may go (C11): the lifetime of the last token issued for it, the
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

    public function hubJwtSecret(): ?string
    {
        $value = $this->config->get('vaulter-collaboration.hub.jwt_secret');

        return is_string($value) && $value !== '' ? $value : null;
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

    /** The longest a room token may live (seconds): D26 bounds the exposure of a lost control message by it. */
    public const int MAX_TOKEN_TTL = 300;

    /** The control transports: Redis pub/sub, HTTP to every hub's management port, or nothing. */
    public const array CONTROL_TRANSPORTS = ['redis', 'http', 'none'];

    /**
     * The lifetime of a room token. A hub renews it before it expires, and a renewal checks the rights again, so a
     * token that missed a revocation lives at most this long (D26).
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
     * @throws InvalidConfigurationException outside 0..60 seconds
     */
    public function hubClockSkew(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.hub.clock_skew', default: 30);
        $seconds = is_numeric($value) ? (int) $value : 30;

        if ($seconds < 0 || $seconds > 60) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.hub.clock_skew must be between 0 and 60 seconds.');
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
     * The JWT signing secret and the webhook secret are two different trust boundaries: the
     * hub owns the webhook secret, so a JWT secret equal to it would let the hub sign any
     * room token (mode rw, any user). Reusing one value is refused at boot in EVERY
     * environment — core's ConfigValidator only warns outside production, and a copied
     * dev value tends to reach production unchanged.
     *
     * @throws InvalidConfigurationException when both secrets are the same value
     */
    public function assertDistinctHubSecrets(): void
    {
        $jwt = $this->hubJwtSecret();

        if ($jwt !== null && $jwt === $this->hubWebhookSecret()) {
            throw new InvalidConfigurationException(
                '[Vaulter] vaulter-collaboration.hub.jwt_secret and webhook_secret must differ. '
                .'Why: the hub holds the webhook secret, so with the same value it could sign a room token for any user. '
                .'Fix: set VAULTER_COLLAB_SECRET (JWT key, Laravel only) and VAULTER_COLLAB_WEBHOOK_SECRET (shared with the hub) to different values.',
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
