<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\WebhookEndpointStoreRepository;

/**
 * Deactivate a webhook endpoint (master plan §2.11 / D14 / M3b): flip is_active off so
 * the relay stops fanning events out to it, and reset its failure_count so a later
 * re-activation starts clean. The row and its delivery history are kept (a soft stop,
 * not a delete — use DeleteWebhookEndpointAction to remove it entirely).
 *
 * Authorization is enforced by the caller before the Action runs.
 */
final readonly class DeactivateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
    ) {}

    public function execute(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        return DB::transaction(fn (): WebhookEndpoint => $this->endpoints->deactivate($endpoint));
    }
}
