<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\WebhookEndpointStoreRepository;

/**
 * Permanently delete a webhook endpoint (master plan §2.11 / D14 / M3b). Its
 * v_webhook_deliveries cascade away via the FK, so no orphaned delivery history is
 * left behind. Irreversible — for a reversible stop use
 * DeactivateWebhookEndpointAction.
 *
 * Authorization is enforced by the caller before the Action runs.
 */
final readonly class DeleteWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
    ) {}

    public function execute(WebhookEndpoint $endpoint): void
    {
        DB::transaction(fn () => $this->endpoints->delete($endpoint));
    }
}
