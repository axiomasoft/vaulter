<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Repositories;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The write side of webhook-endpoint persistence. It
 * shapes the rows — create, attribute update, deactivate, secret rotation, delete —
 * and opens NO transaction (the Action owns it). It never generates a signing secret:
 * the Action mints it (returned once) and hands the stored value here.
 */
#[Internal]
final readonly class WebhookEndpointStoreRepository
{
    public function __construct(
        private WebhooksOptions $webhooksConfig,
    ) {}

    /**
     * Persist a new endpoint with its generated signing_secret, in the given tenant
     * and with the given drive filter (both already resolved by the Action).
     *
     * @param list<string> $events
     */
    public function create(string $url, array $events, string $signingSecret, bool $isActive, ?string $tenantId = null, int|string|null $driveId = null): WebhookEndpoint
    {
        /** @var class-string<WebhookEndpoint> $model */
        $model = $this->webhooksConfig->model('webhook_endpoint');

        // forceFill: signing_secret is deliberately NOT
        // fillable on WebhookEndpoint — Model::create() would silently drop it.
        /** @var WebhookEndpoint $endpoint */
        $endpoint = new $model;
        $endpoint->forceFill([
            'url' => $url,
            'events' => $events,
            'signing_secret' => $signingSecret,
            'is_active' => $isActive,
            'failure_count' => 0,
            'version' => 0,
            'tenant_id' => $tenantId,
            'drive_id' => $driveId,
        ]);
        $endpoint->save();

        return $endpoint;
    }

    /**
     * Apply a partial attribute update — only the supplied (non-null) fields are
     * written, so a PATCH that omits a field leaves it untouched. The tenant is
     * always written (the Action resolves it). signing_secret is never updatable here.
     *
     * @param list<string>|null $events
     */
    public function update(WebhookEndpoint $endpoint, ?string $url, ?array $events, ?bool $isActive, ?string $tenantId = null, int|string|null $driveId = null): WebhookEndpoint
    {
        $attributes = ['tenant_id' => $tenantId];

        if ($url !== null) {
            $attributes['url'] = $url;
        }

        if ($events !== null) {
            $attributes['events'] = $events;
        }

        if ($isActive === true) {
            // Turning an endpoint back on starts it clean: the old failure run and the
            // reason it was off no longer apply.
            $attributes += ['is_active' => true, 'failure_count' => 0, 'failing_since' => null, 'disabled_at' => null, 'disabled_reason' => null];
        }

        if ($isActive === false && $endpoint->is_active) {
            $attributes += ['is_active' => false, 'failure_count' => 0, 'failing_since' => null, 'disabled_at' => now(), 'disabled_reason' => 'manual'];
        }

        if ($driveId !== null) {
            $attributes['drive_id'] = $driveId;
        }

        $endpoint->forceFill($attributes)->save();

        return $endpoint;
    }

    /**
     * Flip the endpoint inactive on the owner's request (the relay stops fanning events
     * out to it) and reset the failure run so a later re-activation starts clean.
     */
    public function deactivate(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $endpoint->forceFill([
            'is_active' => false,
            'failure_count' => 0,
            'failing_since' => null,
            'disabled_at' => now(),
            'disabled_reason' => 'manual',
        ])->save();

        return $endpoint;
    }

    /**
     * Switch the endpoint off because its deliveries kept failing. failing_since stays
     * so the disabled event and the owner can tell when the failures began.
     */
    public function disableFailing(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $endpoint->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => 'failing',
        ])->save();

        return $endpoint;
    }

    /**
     * Make $secret the signing secret and keep the one it replaces for $previousUntil.
     * A null $previousUntil drops the old secret at once (a compromised secret).
     */
    public function rotateSecret(WebhookEndpoint $endpoint, string $secret, ?Carbon $previousUntil): WebhookEndpoint
    {
        $endpoint->forceFill([
            'previous_signing_secret' => $previousUntil === null ? null : $endpoint->signing_secret,
            'previous_secret_expires_at' => $previousUntil,
            'signing_secret' => $secret,
        ])->save();

        return $endpoint;
    }

    /**
     * Permanently delete the endpoint. Its deliveries cascade away via the FK.
     */
    public function delete(WebhookEndpoint $endpoint): void
    {
        $endpoint->delete();
    }
}
