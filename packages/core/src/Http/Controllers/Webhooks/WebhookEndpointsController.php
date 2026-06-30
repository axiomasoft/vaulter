<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Actions\Webhooks\DeactivateWebhookEndpointAction;
use Vaulter\Actions\Webhooks\DeleteWebhookEndpointAction;
use Vaulter\Actions\Webhooks\RegisterWebhookEndpointAction;
use Vaulter\Actions\Webhooks\UpdateWebhookEndpointAction;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Webhooks\RegisterWebhookEndpointRequest;
use Vaulter\Http\Requests\Webhooks\UpdateWebhookEndpointRequest;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\WebhookEndpointReadRepository;

/**
 * The webhook-endpoint MANAGEMENT surface (master plan §2.11 / D14 / M3b): list,
 * register, update, deactivate and delete subscriber endpoints. A GLOBAL admin
 * resource (not node-scoped) — every action is gated by the WebhookEndpointPolicy
 * manage ability via Gate::authorize, so a non-admin 403s.
 *
 * Thin: writes delegate to the Register/Update/Deactivate/Delete Actions, reads to the
 * WebhookEndpointReadRepository. register() returns the signing_secret EXACTLY ONCE;
 * index()/update() never expose it.
 */
final class WebhookEndpointsController extends Controller
{
    public function __construct(
        private readonly WebhookEndpointReadRepository $endpoints,
        private readonly RegisterWebhookEndpointAction $register,
        private readonly UpdateWebhookEndpointAction $update,
        private readonly DeactivateWebhookEndpointAction $deactivate,
        private readonly DeleteWebhookEndpointAction $delete,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookEndpoint::class);

        $endpoints = $this->endpoints
            ->listing()
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return WebhookEndpointResource::collection($endpoints);
    }

    public function store(RegisterWebhookEndpointRequest $request): JsonResponse
    {
        $this->authorize('manage', WebhookEndpoint::class);

        $registered = $this->register->execute(data: $request->toData());

        // The signing_secret is attached here and ONLY here — returned exactly once.
        return WebhookEndpointResource::make($registered->endpoint)
            ->withSecret($registered->signingSecret)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateWebhookEndpointRequest $request, int|string $endpoint): WebhookEndpointResource
    {
        $this->authorize('manage', WebhookEndpoint::class);

        $model = $this->resolve($endpoint);

        $updated = $this->update->execute(endpoint: $model, data: $request->toData());

        return WebhookEndpointResource::make($updated);
    }

    public function deactivate(int|string $endpoint): WebhookEndpointResource
    {
        $this->authorize('manage', WebhookEndpoint::class);

        $model = $this->resolve($endpoint);

        $deactivated = $this->deactivate->execute(endpoint: $model);

        return WebhookEndpointResource::make($deactivated);
    }

    public function destroy(int|string $endpoint): Response
    {
        $this->authorize('manage', WebhookEndpoint::class);

        $model = $this->resolve($endpoint);

        $this->delete->execute(endpoint: $model);

        return response()->noContent();
    }

    /**
     * Resolve the endpoint by id, 404ing when it does not exist. Not route-model bound
     * (the model is swappable), so the controller resolves it through the repository.
     */
    private function resolve(int|string $endpoint): WebhookEndpoint
    {
        $model = $this->endpoints->find($endpoint);

        if (!$model instanceof WebhookEndpoint) {
            throw new NotFoundHttpException('Webhook endpoint not found.');
        }

        return $model;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }
}
