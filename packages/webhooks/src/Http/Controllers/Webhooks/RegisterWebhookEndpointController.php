<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Actions\RegisterWebhookEndpointAction;
use Vaulter\Webhooks\Data\RegisteredWebhookEndpoint;
use Vaulter\Webhooks\Http\Requests\Webhooks\RegisterWebhookEndpointRequest;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Register an endpoint; its signing secret is returned here once and never again. */
#[Internal]
final readonly class RegisterWebhookEndpointController
{
    public function __construct(private RegisterWebhookEndpointAction $register, private WebhookEndpointWrites $writes) {}

    public function __invoke(RegisterWebhookEndpointRequest $request): JsonResponse
    {
        $registered = $this->writes->write($request, 'register', null, fn (): RegisteredWebhookEndpoint => $this->register->execute(data: $request->toData()));

        return (new WebhookEndpointResource($registered->endpoint))->withSecret($registered->signingSecret)->response()->setStatusCode(201);
    }
}
