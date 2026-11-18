<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Webhooks\Actions\DeactivateWebhookEndpointAction;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\EndpointPreconditions;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Stop deliveries to an endpoint; its open deliveries are given up. If-Match carries the endpoint's tag. */
#[Internal]
final readonly class DeactivateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeactivateWebhookEndpointAction $deactivate, private WebhookEndpointWrites $writes, private EndpointPreconditions $preconditions) {}

    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        $expected = $this->preconditions->expectedVersion($request, $endpoint);
        $deactivated = $this->writes->write($request, 'deactivate', $endpoint, function () use ($endpoint, $expected): WebhookEndpointSnapshot {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

            return WebhookEndpointSnapshot::of($this->deactivate->execute(endpoint: $model, expectedVersion: $expected));
        }, ['endpoint' => $endpoint, 'expected_version' => $expected]);

        $resource = new WebhookEndpointResource($deactivated);

        return $resource->response()->setEtag($resource->etag());
    }
}
