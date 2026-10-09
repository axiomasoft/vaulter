<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * The write side of webhook-endpoint persistence (master plan §2.11 / D14 / M3b). It
 * shapes the rows — create, attribute update, deactivate, delete — and opens NO
 * transaction (the Action owns it). It never generates the signing_secret: the Action
 * mints it (returned once) and hands the stored value here.
 */
final readonly class WebhookEndpointStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
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
        $model = $this->vaulterConfig->model('webhook_endpoint');

        // forceFill (P1.4/F-P1.7-04): signing_secret is deliberately NOT
        // fillable on WebhookEndpoint — Model::create() would silently drop it.
        /** @var WebhookEndpoint $endpoint */
        $endpoint = new $model;
        $endpoint->forceFill([
            'url' => $url,
            'events' => $events,
            'signing_secret' => $signingSecret,
            'is_active' => $isActive,
            'failure_count' => 0,
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

        if ($isActive !== null) {
            $attributes['is_active'] = $isActive;
        }

        if ($driveId !== null) {
            $attributes['drive_id'] = $driveId;
        }

        $endpoint->forceFill($attributes)->save();

        return $endpoint;
    }

    /**
     * Flip the endpoint inactive (the relay stops fanning events out to it) and reset
     * the failure counter so a later re-activation starts clean.
     */
    public function deactivate(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $endpoint->forceFill([
            'is_active' => false,
            'failure_count' => 0,
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
