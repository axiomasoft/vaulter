<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/**
 * The policies of the webhook endpoint operations (knowledge p6-design#coverage), named as
 * {@see WebhookEndpointWrites} runs them. A redelivery re-arms a finished delivery under its lock (a repeat while it is
 * pending again is refused) and is a receipt operation: once the delivery has finished again, only the Idempotency-Key
 * tells a retry from a new redelivery, so a keyed retry is answered from the receipt and sends nothing.
 */
#[Internal]
final class WebhooksOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'webhooks.endpoints.register' => new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['webhook-endpoints.store']),
            'webhooks.endpoints.update' => new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['webhook-endpoints.update']),
            'webhooks.endpoints.deactivate' => new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['webhook-endpoints.deactivate']),
            'webhooks.endpoints.rotate_secret' => new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['webhook-endpoints.rotate-secret']),
            'webhooks.endpoints.delete' => new OperationPolicy('webhooks.endpoint', ConcurrencyPolicy::IfMatch, IdempotencyPolicy::Receipt, ['webhook-endpoints.destroy']),
            'webhooks.endpoints.redeliver' => new OperationPolicy('webhooks.delivery', ConcurrencyPolicy::StateMachine, IdempotencyPolicy::Receipt, ['webhook-deliveries.redeliver']),
        ];
    }
}
