<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\UpdateWebhookEndpointAction;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Requests\Webhooks\UpdateWebhookEndpointRequest;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

final readonly class UpdateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private UpdateWebhookEndpointAction $update) {}

    public function __invoke(UpdateWebhookEndpointRequest $request, string $endpoint): JsonResponse
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

        return (new WebhookEndpointResource($this->update->execute(endpoint: $model, data: $request->toData())))->response();
    }
}
