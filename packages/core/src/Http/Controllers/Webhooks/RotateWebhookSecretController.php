<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\RotateWebhookSecretAction;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Http\Requests\Webhooks\RotateWebhookSecretRequest;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

/** A new signing secret, returned once; the old one keeps verifying during the overlap. */
final readonly class RotateWebhookSecretController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private RotateWebhookSecretAction $rotate) {}

    public function __invoke(RotateWebhookSecretRequest $request, string $endpoint): JsonResponse
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
        $rotated = $this->rotate->execute(endpoint: $model, overlapSeconds: $request->overlapSeconds());

        return (new WebhookEndpointResource($model->refresh()))->withSecret($rotated->secret)->response();
    }
}
