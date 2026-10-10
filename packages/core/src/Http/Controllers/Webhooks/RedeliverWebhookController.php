<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\RedeliverWebhookAction;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotFoundException;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Resources\Webhooks\WebhookDeliveryResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

/** Queue a finished (delivered or dead) delivery again; 202, the send happens later. */
final readonly class RedeliverWebhookController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private WebhookDeliveryStoreRepository $deliveries, private RedeliverWebhookAction $redeliver) {}

    public function __invoke(string $endpoint, string $delivery): JsonResponse
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
        $row = $this->deliveries->findForEndpoint($model, $delivery) ?? throw WebhookDeliveryNotFoundException::forId($delivery);

        return (new WebhookDeliveryResource($this->redeliver->execute($row)))->response()->setStatusCode(202);
    }
}
