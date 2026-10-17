<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\DeactivateWebhookEndpointAction;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Stop deliveries to an endpoint; its open deliveries are given up. */
#[Internal]
final readonly class DeactivateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeactivateWebhookEndpointAction $deactivate, private WebhookEndpointWrites $writes) {}

    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        $deactivated = $this->writes->write($request, 'deactivate', $endpoint, function () use ($endpoint): WebhookEndpoint {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

            return $this->deactivate->execute(endpoint: $model);
        });

        return (new WebhookEndpointResource($deactivated))->response();
    }
}
