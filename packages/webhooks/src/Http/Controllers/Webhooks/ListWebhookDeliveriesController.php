<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Packman\Attributes\Internal;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookDeliveryResource;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

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
