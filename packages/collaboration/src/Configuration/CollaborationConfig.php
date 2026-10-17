<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Data\RoomRef;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Support\Identity;

/**
 * The typed reader of the `vaulter-collaboration` configuration root: the transport, the hub (URL, secrets,
 * token lifetime) and the largest Yjs state a store may write. The room channel prefix is the documents package's
 * ({@see DocumentsConfig::channelPrefix()}): the comment events of a document broadcast on the same channels.
 */
#[Api]
final readonly class CollaborationConfig
{
    #[Internal]
    public function __construct(private Repository $config, private DocumentsConfig $documents) {}

    /** @throws InvalidConfigurationException for the removed reverb driver */
    public function driver(): string
    {
        $value = $this->config->get(key: 'vaulter-collaboration.driver', default: 'hocuspocus');

        // Reverb could not persist Yjs state server-side, so a room could never be
        // fenced off after a restore; failing loudly beats silently switching transport.
        if ($value === 'reverb') {
            throw new InvalidConfigurationException(
                '[Vaulter] The reverb collaboration driver was removed; set vaulter-collaboration.driver to hocuspocus. '
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/guides/collaboration.md',
            );
        }

        return is_string($value) ? $value : 'hocuspocus';
    }

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

    /**
     * Rights are re-read only on the hub's next authenticate/load/store, so a token
     * may not outlive an hour.
     *
     * @throws InvalidConfigurationException outside 1..3600 seconds
     */
    public function hubTokenTtl(): int
    {
        $value = $this->config->get(key: 'vaulter-collaboration.hub.token_ttl', default: 3600);
        $seconds = is_numeric($value) ? (int) $value : 3600;

        if ($seconds < 1 || $seconds > 3600) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-collaboration.hub.token_ttl must be between 1 and 3600 seconds.');
        }

        return $seconds;
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

    /**
     * An active collaboration module needs a nonempty JWT secret and a different nonempty webhook secret: production
     * refuses to boot without them, any other environment logs a warning.
     */
    public function assertSecrets(Application $app): void
    {
        $jwt = $this->hubJwtSecret();
        $webhook = $this->hubWebhookSecret();
        if ($jwt !== null && $webhook !== null && $jwt !== $webhook) {
            return;
        }
        $message = '[Vaulter] Collaboration requires nonempty, different JWT and webhook secrets.';
        if ($app->environment('production')) {
            throw new InvalidConfigurationException($message);
        }
        Log::warning($message);
    }
}
