<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packman\Attributes\Internal;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Actions\DeleteWebhookEndpointAction;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Http\Resources\Webhooks\EndpointPreconditions;
use Vaulter\Webhooks\Idempotency\EndpointDeleteReceipt;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\WebhookEndpointWrites;

/** Delete an endpoint; If-Match carries its tag. The receipt keeps its id and the scope the right was proven in. */
#[Internal]
final readonly class DeleteWebhookEndpointController
{
    public function __construct(private WebhookEndpointReadRepository $endpoints, private DeleteWebhookEndpointAction $delete, private WebhookEndpointWrites $writes, private EndpointPreconditions $preconditions, private TenancyBoundary $tenancy) {}

    public function __invoke(Request $request, string $endpoint): Response
    {
        $expected = $this->preconditions->expectedVersion($request, $endpoint);
        $this->writes->write($request, 'delete', $endpoint, function () use ($endpoint, $expected): EndpointDeleteReceipt {
            $model = $this->endpoints->find($endpoint) ?? throw WebhookEndpointNotFoundException::forId($endpoint);
            $receipt = new EndpointDeleteReceipt(id: Identity::stringify($model->getKey()), tenantId: $this->tenancy->enforced() ? $this->tenancy->current() : null);
            $this->delete->execute(endpoint: $model, expectedVersion: $expected);

            return $receipt;
        }, ['endpoint' => $endpoint, 'expected_version' => $expected]);

        return new Response(status: 204);
    }
}
