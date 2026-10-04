<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Vaulter\Data\Webhooks\UpdateWebhookEndpointInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointStoreRepository;

/**
 * Update a webhook endpoint's mutable attributes — url, subscribed events, active
 * flag (master plan §2.11 / D14 / M3b). A partial command: a null field is left
 * unchanged. The signing_secret is NEVER touched here (it is shown once at
 * registration; rotation means re-registering).
 *
 * Authorization is enforced by the caller before the Action runs.
 */
final readonly class UpdateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
    ) {}

    public function execute(WebhookEndpoint $endpoint, UpdateWebhookEndpointInput $data): WebhookEndpoint
    {
        return $this->database->transaction(fn (): WebhookEndpoint => $this->endpoints->update(
            endpoint: $endpoint,
            url: $data->url,
            events: $data->events,
            isActive: $data->isActive,
        ));
    }
}
