<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Illuminate\Support\Facades\Bus;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Support\Identity;

/**
 * Send a finished delivery (delivered or dead) again. The SAME row is re-armed — pending,
 * attempts 0, due now — so the receiver sees the same webhook-id and can deduplicate;
 * the retry schedule starts over. A delivery that is still pending or failed is being
 * retried already, and an inactive endpoint would only have it killed again.
 *
 * Authorization is enforced by the caller before the Action runs.
 *
 * @throws WebhookDeliveryNotRedeliverableException
 */
final readonly class RedeliverWebhookAction
{
    public function __construct(private VaulterDatabase $database) {}

    public function execute(WebhookDelivery $delivery): WebhookDelivery
    {
        $id = Identity::stringify($delivery->getKey());

        $this->database->transaction(function () use ($delivery, $id): void {
            $current = $delivery->newQuery()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if (!in_array($current->status, ['delivered', 'dead'], true)) {
                throw WebhookDeliveryNotRedeliverableException::notTerminal($id, $current->status);
            }

            $endpoint = $current->endpoint;

            if (!$endpoint instanceof WebhookEndpoint || !$endpoint->is_active) {
                throw WebhookDeliveryNotRedeliverableException::endpointInactive($id);
            }

            $current->forceFill([
                'status' => 'pending',
                'attempts' => 0,
                'next_retry_at' => now(),
                'last_error' => null,
                'claim_token' => null,
                'claim_until' => null,
            ])->save();

            $this->database->afterCommit(static function () use ($id): void {
                Bus::dispatch(new DeliverWebhook(deliveryId: $id));
            });
        });

        return $delivery->refresh();
    }
}
