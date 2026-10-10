<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Vaulter\Actions\Webhooks\DeleteWebhookEndpointAction;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;

final readonly class DeleteWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeleteWebhookEndpointAction $delete) {}

    public function __invoke(string $endpoint): Response
    {
        Gate::authorize('manage', WebhookEndpoint::class);
        $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
        $this->delete->execute(endpoint: $model);

        return new Response(status: 204);
    }
}
