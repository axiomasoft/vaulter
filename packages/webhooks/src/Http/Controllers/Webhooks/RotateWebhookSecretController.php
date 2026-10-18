<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\RotateWebhookSecretAction;
use Vaulter\Webhooks\Data\MintedEndpointSecret;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Requests\Webhooks\RotateWebhookSecretRequest;
use Vaulter\Webhooks\Http\Resources\Webhooks\EndpointPreconditions;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** A new signing secret, returned once; the old one keeps verifying during the overlap. If-Match carries the endpoint's tag. */
#[Internal]
final readonly class RotateWebhookSecretController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private RotateWebhookSecretAction $rotate, private WebhookEndpointWrites $writes, private EndpointPreconditions $preconditions) {}

    public function __invoke(RotateWebhookSecretRequest $request, string $endpoint): JsonResponse
    {
        $overlap = $request->overlapSeconds();
        $expected = $this->preconditions->expectedVersion($request, $endpoint);
        $minted = $this->writes->write($request, 'rotate_secret', $endpoint, function () use ($endpoint, $overlap, $expected): MintedEndpointSecret {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $rotated = $this->rotate->execute(endpoint: $model, overlapSeconds: $overlap, expectedVersion: $expected);

            return new MintedEndpointSecret(endpoint: WebhookEndpointSnapshot::of($model->refresh()), secret: $rotated->secret);
        }, ['endpoint' => $endpoint, 'overlap_seconds' => $overlap, 'expected_version' => $expected]);

        $resource = (new WebhookEndpointResource($minted->endpoint))->withSecret($minted->secret);

        return $resource->response()->setEtag($resource->etag());
    }
}
