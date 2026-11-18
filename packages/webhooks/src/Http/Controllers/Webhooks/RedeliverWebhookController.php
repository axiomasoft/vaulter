<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Actions\RedeliverWebhookAction;
use Vaulter\Webhooks\Exceptions\WebhookDeliveryNotFoundException;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookDeliveryResource;
use Vaulter\Webhooks\Idempotency\RedeliveryReceipt;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/**
 * Queue a finished (delivered or dead) delivery again; 202, the send happens later. With an Idempotency-Key the write is
 * a receipt operation: a retry under the same key answers with the delivery as it is now and queues nothing, so a
 * lost answer does not send the webhook a second time.
 */
#[Internal]
final readonly class RedeliverWebhookController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private WebhookDeliveryStoreRepository $deliveries, private RedeliverWebhookAction $redeliver, private WebhookEndpointWrites $writes) {}

    public function __invoke(Request $request, string $endpoint, string $delivery): JsonResponse
    {
        $redelivered = null;
        $receipt = $this->writes->write($request, 'redeliver', $endpoint, function () use ($endpoint, $delivery, &$redelivered): RedeliveryReceipt {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $row = $this->deliveries->findForEndpoint($model, $delivery) ?? throw WebhookDeliveryNotFoundException::forId($delivery);
            $redelivered = $this->redeliver->execute($row);

            return new RedeliveryReceipt(id: Identity::stringify($redelivered->getKey()), endpointId: Identity::stringify($model->getKey()));
        }, ['endpoint' => $endpoint, 'delivery' => $delivery]);

        // A replay ran no attempt: the delivery of the receipt is read as it is now.
        if (!$redelivered instanceof WebhookDelivery) {
            $model = $this->endpoints->find($receipt->endpointId) ?? throw WebhookEndpointNotFoundException::forId($receipt->endpointId);
            $redelivered = $this->deliveries->findForEndpoint($model, $receipt->id) ?? throw WebhookDeliveryNotFoundException::forId($receipt->id);
        }

        return new WebhookDeliveryResource($redelivered)->response()->setStatusCode(202);
    }
}
