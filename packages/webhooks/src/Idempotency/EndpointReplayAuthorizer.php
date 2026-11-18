<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Idempotency;

use Illuminate\Support\Facades\Gate;
use Packman\Attributes\Internal;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;

/**
 * The current authorization of a replayed endpoint write (ID2), with the same gate as the write itself: the
 * `manage` ability on endpoints, checked now — a revoked ability refuses the replay as it would a new request, and
 * the stored result, a signing secret included, is not returned.
 *
 * The receipt records the scope the right was proven in: `webhook_endpoints` of the tenant (`all` without tenancy). An
 * endpoint that still exists must still be there (a register or a rotation replayed after the endpoint was deleted is
 * a 404, not its old secret); a deleted one is judged on the recorded scope alone, which must be the current tenant's.
 */
#[Internal]
final readonly class EndpointReplayAuthorizer implements ReplayAuthorizer
{
    public const string COLLECTION = 'webhook_endpoints';

    private const string ALL = 'all';

    public function __construct(private WebhookEndpointReadRepository $endpoints, private TenancyBoundary $tenancy, private string $endpoint) {}

    public function scope(mixed $result): ResourceRef
    {
        // The tenant scope in which the caller proved the management right.
        $tenant = $result instanceof EndpointDeleteReceipt ? $result->tenantId : $this->currentTenant();

        return new ResourceRef(self::COLLECTION, $tenant ?? self::ALL);
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        Gate::authorize('manage', WebhookEndpoint::class);

        if ($scope !== null && $scope->kind === self::COLLECTION && $scope->id !== ($this->currentTenant() ?? self::ALL)) {
            // A receipt of another tenant's scope is as unknown as a missing endpoint.
            throw WebhookEndpointNotFoundException::forId($this->endpoint);
        }
        if ($resource?->kind === 'webhook_endpoint' && $this->endpoints->find($resource->id) === null) {
            throw WebhookEndpointNotFoundException::forId($resource->id);
        }
    }

    private function currentTenant(): ?string
    {
        return $this->tenancy->enforced() ? $this->tenancy->current() : null;
    }
}
