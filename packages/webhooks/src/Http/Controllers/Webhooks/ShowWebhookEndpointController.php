<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

#[Internal]
final readonly class ShowWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints) {}

    public function __invoke(string $endpoint): JsonResponse
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

        return (new WebhookEndpointResource($model))->response();
    }
}
