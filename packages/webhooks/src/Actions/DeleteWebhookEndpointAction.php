<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointStoreRepository;

/**
 * Permanently delete a webhook endpoint. Its
 * v_webhook_deliveries cascade away via the FK, so no orphaned delivery history is
 * left behind. Irreversible — for a reversible stop use
 * DeactivateWebhookEndpointAction.
 *
 * Authorization is enforced by the caller before the Action runs.
 */
#[Internal]
final readonly class DeleteWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
        private AggregateVersion $versions,
        private TenancyBoundary $tenancy,
    ) {}

    public function execute(WebhookEndpoint $endpoint, ?int $expectedVersion = null): void
    {
        $this->database->transaction(function () use ($endpoint, $expectedVersion): void {
            // The stored row under its lock: a rotation or an update that landed in between is a 412, a deletion a 404.
            $row = $endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->first()
                ?? throw WebhookEndpointNotFoundException::forId(Identity::stringify($endpoint->getKey()));
            if ($this->tenancy->enforced() && TenancyBoundary::normalize($row->tenant_id) !== $this->tenancy->current()) {
                throw WebhookEndpointNotFoundException::forId(Identity::stringify($row->getKey()));
            }
            $this->versions->assertExpected($row, 'version', $expectedVersion, WebhookEndpoint::AGGREGATE);
            $this->endpoints->delete($row);
        });
    }
}
