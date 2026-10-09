<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookEndpointStoreRepository;
use Vaulter\Support\Identity;

/**
 * Deactivate a webhook endpoint (master plan §2.11 / D14 / M3b): flip is_active off so
 * the relay stops fanning events out to it (disabled_reason 'manual'), and finish its
 * waiting and retrying deliveries as dead — they would never be sent, and a dead row
 * can be redelivered once the endpoint is back. The row and its delivery history are
 * kept (a soft stop, not a delete — use DeleteWebhookEndpointAction to remove it).
 *
 * Authorization is enforced by the caller before the Action runs.
 */
final readonly class DeactivateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private WebhookDeliveryStoreRepository $deliveries,
        private VaulterDatabase $database,
    ) {}

    public function execute(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        return $this->database->transaction(function () use ($endpoint): WebhookEndpoint {
            $this->endpoints->deactivate($endpoint);
            $this->deliveries->killOpenForEndpoint(endpointId: Identity::stringify($endpoint->getKey()), reason: 'endpoint_disabled');

            return $endpoint;
        });
    }
}
