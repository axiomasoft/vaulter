<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\DeactivateWebhookEndpointAction;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

/** Stop deliveries to an endpoint; its open deliveries are given up. */
final readonly class DeactivateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeactivateWebhookEndpointAction $deactivate) {}

    public function __invoke(string $endpoint): JsonResponse
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

        return (new WebhookEndpointResource($this->deactivate->execute(endpoint: $model)))->response();
    }
}
