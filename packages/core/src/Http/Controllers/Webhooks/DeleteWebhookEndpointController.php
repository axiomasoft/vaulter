<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Actions\Webhooks\DeleteWebhookEndpointAction;
use Vaulter\Exceptions\Webhooks\WebhookEndpointNotFoundException;
use Vaulter\Repositories\Webhooks\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

final readonly class DeleteWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeleteWebhookEndpointAction $delete, private WebhookEndpointWrites $writes) {}

    public function __invoke(Request $request, string $endpoint): Response
    {
        $this->writes->write($request, 'delete', $endpoint, function () use ($endpoint): void {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $this->delete->execute(endpoint: $model);
        });

        return new Response(status: 204);
    }
}
