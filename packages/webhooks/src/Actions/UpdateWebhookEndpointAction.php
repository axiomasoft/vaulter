<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Repositories\WebhookEndpointStoreRepository;

/**
 * Update a webhook endpoint's mutable attributes — url, subscribed events, active
 * flag. A partial command: a null field is left
 * unchanged. The signing secret is NEVER touched here (it is shown once; use
 * RotateWebhookSecretAction). Switching an active endpoint off finishes its open
 * deliveries as dead, as DeactivateWebhookEndpointAction does; switching it on clears
 * the failure run and the disable reason.
 *
 * An update keeps the endpoint's tenant. Under strict tenancy the locked row must belong to the current tenant;
 * assigning legacy endpoints to a tenant requires an explicit administrative migration. A new drive filter must
 * name a drive visible in the current tenant.
 *
 * Authorization is enforced by the caller before the Action runs.
 */
#[Internal]
final readonly class UpdateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private WebhookDeliveryStoreRepository $deliveries,
        private VaulterDatabase $database,
        private TenancyBoundary $tenancy,
        private DriveReadRepository $drives,
        private AggregateVersion $versions,
    ) {}

    public function execute(WebhookEndpoint $endpoint, UpdateWebhookEndpointInput $data, ?int $expectedVersion = null): WebhookEndpoint
    {
        return $this->database->transaction(function () use ($endpoint, $data, $expectedVersion): WebhookEndpoint {
            // Decide from the row as it is now, not from the copy the caller loaded earlier: a
            // stale "inactive" copy would skip killing the deliveries created since.
            // A deletion that committed first leaves no row: the endpoint is gone, not an internal error.
            $current = $endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->first()
                ?? throw WebhookEndpointNotFoundException::forId(Identity::stringify($endpoint->getKey()));
            $tenant = TenancyBoundary::normalize($current->tenant_id);
            if ($this->tenancy->enforced() && $tenant !== $this->tenancy->current()) {
                throw WebhookEndpointNotFoundException::forId(Identity::stringify($current->getKey()));
            }
            // The version the caller read is compared on the locked row.
            $this->versions->assertExpected($current, 'version', $expectedVersion, WebhookEndpoint::AGGREGATE);
            $endpoint->setRawAttributes($current->getAttributes(), sync: true);
            $wasActive = $endpoint->is_active;

            $this->endpoints->update(
                endpoint: $endpoint,
                url: $data->url,
                events: $data->events,
                isActive: $data->isActive,
                tenantId: $tenant,
                driveId: $data->driveId === null ? null : $this->visibleDrive(driveId: $data->driveId),
            );

            if ($wasActive && $data->isActive === false) {
                $this->deliveries->killOpenForEndpoint(endpointId: Identity::stringify($endpoint->getKey()), reason: 'endpoint_disabled');
            }

            $this->versions->bump($endpoint, 'version', WebhookEndpoint::AGGREGATE);

            return $endpoint;
        });
    }

    private function visibleDrive(string $driveId): int|string
    {
        $drive = $this->drives->find($driveId);
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }

        return Identity::key($drive->getKey()) ?? throw NodeNotFoundException::forId('drive');
    }
}
