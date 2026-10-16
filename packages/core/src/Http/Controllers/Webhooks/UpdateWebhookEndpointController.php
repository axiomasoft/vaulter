<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Actions\Webhooks\UpdateWebhookEndpointAction;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Requests\Webhooks\UpdateWebhookEndpointRequest;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

#[Internal]
final readonly class UpdateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private UpdateWebhookEndpointAction $update, private WebhookEndpointWrites $writes) {}

    public function __invoke(UpdateWebhookEndpointRequest $request, string $endpoint): JsonResponse
    {
        $updated = $this->writes->write($request, 'update', $endpoint, function () use ($request, $endpoint): WebhookEndpoint {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

            return $this->update->execute(endpoint: $model, data: $request->toData());
        });

        return (new WebhookEndpointResource($updated))->response();
    }
}
