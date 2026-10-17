<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\RotateWebhookSecretAction;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Requests\Webhooks\RotateWebhookSecretRequest;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** A new signing secret, returned once; the old one keeps verifying during the overlap. */
#[Internal]
final readonly class RotateWebhookSecretController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private RotateWebhookSecretAction $rotate, private WebhookEndpointWrites $writes) {}

    public function __invoke(RotateWebhookSecretRequest $request, string $endpoint): JsonResponse
    {
        [$model, $rotated] = $this->writes->write($request, 'rotate_secret', $endpoint, function () use ($request, $endpoint): array {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);

            return [$model, $this->rotate->execute(endpoint: $model, overlapSeconds: $request->overlapSeconds())];
        });

        return (new WebhookEndpointResource($model->refresh()))->withSecret($rotated->secret)->response();
    }
}
