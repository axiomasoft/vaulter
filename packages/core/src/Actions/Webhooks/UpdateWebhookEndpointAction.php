<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Vaulter\Data\Webhooks\UpdateWebhookEndpointInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookEndpointStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;

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
final readonly class UpdateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private WebhookDeliveryStoreRepository $deliveries,
        private VaulterDatabase $database,
        private TenancyBoundary $tenancy,
        private DriveReadRepository $drives,
    ) {}

    public function execute(WebhookEndpoint $endpoint, UpdateWebhookEndpointInput $data): WebhookEndpoint
    {
        return $this->database->transaction(function () use ($endpoint, $data): WebhookEndpoint {
            // Decide from the row as it is now, not from the copy the caller loaded earlier: a
            // stale "inactive" copy would skip killing the deliveries created since.
            $endpoint->setRawAttributes($endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), sync: true);
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
