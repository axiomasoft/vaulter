<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Actions\Webhooks\RedeliverWebhookAction;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotFoundException;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Resources\Webhooks\WebhookDeliveryResource;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Queue a finished (delivered or dead) delivery again; 202, the send happens later. */
#[Internal]
final readonly class RedeliverWebhookController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private WebhookDeliveryStoreRepository $deliveries, private RedeliverWebhookAction $redeliver, private WebhookEndpointWrites $writes) {}

    public function __invoke(Request $request, string $endpoint, string $delivery): JsonResponse
    {
        $redelivered = $this->writes->write($request, 'redeliver', $endpoint, function () use ($endpoint, $delivery): WebhookDelivery {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $row = $this->deliveries->findForEndpoint($model, $delivery) ?? throw WebhookDeliveryNotFoundException::forId($delivery);

            return $this->redeliver->execute($row);
        });

        return (new WebhookDeliveryResource($redelivered))->response()->setStatusCode(202);
    }
}
