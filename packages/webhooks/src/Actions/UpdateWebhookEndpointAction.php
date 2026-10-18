<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;
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
 * The endpoint is (re)assigned to the current tenant, as at registration, so a
 * pre-tenancy endpoint (tenant null) is brought into its manager's tenant by any
 * update. A new drive filter must name a drive visible in that tenant.
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
            $endpoint->setRawAttributes($endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), sync: true);
            // CC1: the version the caller read is compared on the locked row.
            $this->versions->assertExpected($endpoint, 'version', $expectedVersion, WebhookEndpoint::AGGREGATE);
            $wasActive = $endpoint->is_active;

            $this->endpoints->update(
                endpoint: $endpoint,
                url: $data->url,
                events: $data->events,
                isActive: $data->isActive,
                tenantId: $this->tenancy->enforced() ? $this->tenancy->current() : null,
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
        $intKeys = (new Drive)->getKeyType() === 'int';
        $drive = $intKeys && !ctype_digit($driveId) ? null : $this->drives->find($intKeys ? (int) $driveId : $driveId);
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }

        return Identity::key($drive->getKey()) ?? throw NodeNotFoundException::forId('drive');
    }
}
