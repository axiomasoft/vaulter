<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Repositories\WebhookEndpointStoreRepository;

/**
 * Deactivate a webhook endpoint: flip is_active off so
 * the relay stops fanning events out to it (disabled_reason 'manual'), and finish its
 * waiting and retrying deliveries as dead — they would never be sent, and a dead row
 * can be redelivered once the endpoint is back. The row and its delivery history are
 * kept (a soft stop, not a delete — use DeleteWebhookEndpointAction to remove it).
 *
 * Authorization is enforced by the caller before the Action runs.
 */
#[Internal]
final readonly class DeactivateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private WebhookDeliveryStoreRepository $deliveries,
        private VaulterDatabase $database,
        private AggregateVersion $versions,
    ) {}

    public function execute(WebhookEndpoint $endpoint, ?int $expectedVersion = null): WebhookEndpoint
    {
        return $this->database->transaction(function () use ($endpoint, $expectedVersion): WebhookEndpoint {
            // Same lock order as a delivery settlement and a redelivery: endpoint first.
            $endpoint->setRawAttributes($endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), sync: true);
            // CC1: the version the caller read is compared on the locked row.
            $this->versions->assertExpected($endpoint, 'version', $expectedVersion, WebhookEndpoint::AGGREGATE);
            $this->endpoints->deactivate($endpoint);
            $this->deliveries->killOpenForEndpoint(endpointId: Identity::stringify($endpoint->getKey()), reason: 'endpoint_disabled');
            $this->versions->bump($endpoint, 'version', WebhookEndpoint::AGGREGATE);

            return $endpoint;
        });
    }
}
