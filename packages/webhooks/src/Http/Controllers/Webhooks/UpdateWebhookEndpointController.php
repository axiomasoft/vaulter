<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\UpdateWebhookEndpointAction;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Requests\Webhooks\UpdateWebhookEndpointRequest;
use Vaulter\Webhooks\Http\Resources\Webhooks\EndpointPreconditions;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Update an endpoint; If-Match carries its tag (428 without it, 412 stale_write when it changed since). */
#[Internal]
final readonly class UpdateWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private UpdateWebhookEndpointAction $update, private WebhookEndpointWrites $writes, private EndpointPreconditions $preconditions) {}

    public function __invoke(UpdateWebhookEndpointRequest $request, string $endpoint): JsonResponse
    {
        $data = $request->toData();
        $expected = $this->preconditions->expectedVersion($request, $endpoint);
        $updated = $this->writes->write($request, 'update', $endpoint, function () use ($endpoint, $data, $expected): WebhookEndpointSnapshot {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

            return WebhookEndpointSnapshot::of($this->update->execute(endpoint: $model, data: $data, expectedVersion: $expected));
        }, [
            'endpoint' => $endpoint, 'url' => $data->url, 'events' => $data->events, 'is_active' => $data->isActive,
            'drive_id' => $data->driveId, 'expected_version' => $expected,
        ]);

        $resource = new WebhookEndpointResource($updated);

        return $resource->response()->setEtag($resource->etag());
    }
}
