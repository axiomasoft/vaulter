<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Resources\Webhooks\WebhookDeliveryResource;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

#[Internal]
final readonly class ListWebhookDeliveriesController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private WebhookDeliveryStoreRepository $deliveries) {}

    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

        return PageResponse::fromPaginator(
            $this->deliveries->pageForEndpoint($model, PageResponse::cursor($request), PageResponse::perPage($request)),
            static fn (WebhookDelivery $delivery): WebhookDeliveryResource => new WebhookDeliveryResource($delivery),
        );
    }
}
