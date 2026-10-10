<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

/**
 * Webhook endpoints are a global admin surface, not a drive resource: every controller
 * of this namespace authorizes through the WebhookEndpointPolicy and calls the webhook
 * actions and repositories directly.
 */
final readonly class ListWebhookEndpointsController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);

        return PageResponse::fromPaginator(
            $this->endpoints->listing()->cursorPaginate(perPage: PageResponse::perPage($request), cursor: PageResponse::cursor($request)),
            static fn (WebhookEndpoint $endpoint): WebhookEndpointResource => new WebhookEndpointResource($endpoint),
        );
    }
}
