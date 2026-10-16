<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Actions\Webhooks\DeactivateWebhookEndpointAction;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;
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
