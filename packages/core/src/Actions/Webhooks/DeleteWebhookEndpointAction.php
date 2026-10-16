<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointStoreRepository;

/**
 * Permanently delete a webhook endpoint. Its
 * v_webhook_deliveries cascade away via the FK, so no orphaned delivery history is
 * left behind. Irreversible — for a reversible stop use
 * DeactivateWebhookEndpointAction.
 *
 * Authorization is enforced by the caller before the Action runs.
 */
#[Internal]
final readonly class DeleteWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
    ) {}

    public function execute(WebhookEndpoint $endpoint): void
    {
        $this->database->transaction(fn () => $this->endpoints->delete($endpoint));
    }
}
