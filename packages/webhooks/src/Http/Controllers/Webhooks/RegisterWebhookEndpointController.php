<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\RegisterWebhookEndpointAction;
use Vaulter\Webhooks\Data\MintedEndpointSecret;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Http\Requests\Webhooks\RegisterWebhookEndpointRequest;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Register an endpoint (an append, idempotent by key); its signing secret is returned here once and never again. */
#[Internal]
final readonly class RegisterWebhookEndpointController
{
    public function __construct(private RegisterWebhookEndpointAction $register, private WebhookEndpointWrites $writes) {}

    public function __invoke(RegisterWebhookEndpointRequest $request): JsonResponse
    {
        $data = $request->toData();
        $minted = $this->writes->write($request, 'register', null, function () use ($data): MintedEndpointSecret {
            $registered = $this->register->execute(data: $data);

            return new MintedEndpointSecret(endpoint: WebhookEndpointSnapshot::of($registered->endpoint), secret: $registered->signingSecret);
        }, ['url' => $data->url, 'events' => $data->events, 'is_active' => $data->isActive, 'drive_id' => $data->driveId]);

        $resource = (new WebhookEndpointResource($minted->endpoint))->withSecret($minted->secret);

        return $resource->response()->setStatusCode(201)->setEtag($resource->etag());
    }
}
