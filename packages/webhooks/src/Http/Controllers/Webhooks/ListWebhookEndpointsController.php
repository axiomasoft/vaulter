<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Packman\Attributes\Internal;
use Vaulter\Drive\Support\KeysetCursor;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;
use Vaulter\Webhooks\Http\Resources\Webhooks\WebhookEndpointResource;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

/**
 * Webhook endpoints are a global admin surface, not a drive resource: every controller
 * of this namespace authorizes through the WebhookEndpointPolicy and calls the webhook
 * actions and repositories directly.
 */
#[Internal]
final readonly class ListWebhookEndpointsController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);
        $listing = $this->endpoints->listing();

        return PageResponse::fromPaginator(
            $listing->cursorPaginate(perPage: PageResponse::perPage($request), cursor: KeysetCursor::open(PageResponse::cursor($request), ['created_at' => KeysetCursor::TIMESTAMP, 'id' => KeysetCursor::KEY], $listing->getModel())),
            static fn (WebhookEndpoint $endpoint): WebhookEndpointResource => new WebhookEndpointResource(WebhookEndpointSnapshot::of($endpoint)),
        );
    }
}
