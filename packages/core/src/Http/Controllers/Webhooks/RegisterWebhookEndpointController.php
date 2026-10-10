<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\RegisterWebhookEndpointAction;
use Vaulter\Http\Requests\Webhooks\RegisterWebhookEndpointRequest;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/** Register an endpoint; its signing secret is returned here once and never again. */
final readonly class RegisterWebhookEndpointController
{
    public function __construct(private RegisterWebhookEndpointAction $register) {}

    public function __invoke(RegisterWebhookEndpointRequest $request): JsonResponse
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $registered = $this->register->execute(data: $request->toData());

        return (new WebhookEndpointResource($registered->endpoint))->withSecret($registered->signingSecret)->response()->setStatusCode(201);
    }
}
