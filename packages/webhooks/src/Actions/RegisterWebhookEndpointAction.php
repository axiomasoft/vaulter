<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\Webhooks\Data\RegisteredWebhookEndpoint;
use Vaulter\Webhooks\Data\RegisterWebhookEndpointInput;
use Vaulter\Webhooks\Repositories\WebhookEndpointStoreRepository;
use Vaulter\Webhooks\WebhookSecret;

/**
 * Register a webhook endpoint. It GENERATES the
 * signing secret here — a Standard Webhooks "whsec_" secret the receiver verifies
 * signatures with — persists it, and returns the raw secret ONCE via the result. The
 * resource never re-exposes it, so it is unrecoverable afterward (the owner gets a new
 * one with RotateWebhookSecretAction).
 *
 * The endpoint belongs to the current tenant under strict tenancy (null otherwise)
 * and receives only that tenant's events. A drive filter must name a drive visible
 * in that tenant; any other id is reported as not found, like every other lookup.
 *
 * Authorization (the actor may manage webhooks) is enforced by the caller before the
 * Action runs — the Action persists, it does not re-authorize.
 */
#[Internal]
final readonly class RegisterWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
        private TenancyBoundary $tenancy,
        private DriveReadRepository $drives,
    ) {}

    public function execute(RegisterWebhookEndpointInput $data): RegisteredWebhookEndpoint
    {
        $secret = WebhookSecret::generate();

        return $this->database->transaction(function () use ($data, $secret): RegisteredWebhookEndpoint {
            $endpoint = $this->endpoints->create(
                url: $data->url,
                events: $data->events,
                signingSecret: $secret,
                isActive: $data->isActive,
                tenantId: $this->tenancy->enforced() ? $this->tenancy->current() : null,
                driveId: $data->driveId === null ? null : $this->visibleDrive(driveId: $data->driveId),
            );

            return new RegisteredWebhookEndpoint(endpoint: $endpoint, signingSecret: $secret);
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
