<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Actions;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Data\RotatedWebhookSecret;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointStoreRepository;
use Vaulter\Webhooks\WebhookSecret;

/**
 * Replace an endpoint's signing secret. For the overlap window both secrets sign every
 * delivery (two signatures in webhook-signature), so the receiver can deploy the new
 * secret without dropping requests. The new secret is returned once, like at
 * registration. An overlap of 0 drops the old secret at once — for a leaked secret.
 *
 * Authorization is enforced by the caller before the Action runs.
 */
#[Internal]
final readonly class RotateWebhookSecretAction
{
    private const int MAX_OVERLAP_SECONDS = 604800;

    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
        private WebhooksConfig $config,
        private AggregateVersion $versions,
    ) {}

    public function execute(WebhookEndpoint $endpoint, ?int $overlapSeconds = null, ?int $expectedVersion = null): RotatedWebhookSecret
    {
        $overlap = min(max($overlapSeconds ?? $this->config->secretRotationOverlap(), 0), self::MAX_OVERLAP_SECONDS);
        $secret = WebhookSecret::generate();

        return $this->database->transaction(function () use ($endpoint, $overlap, $secret, $expectedVersion): RotatedWebhookSecret {
            // Rotate from the stored row: two rotations racing must not both keep the same "previous".
            $current = $endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail();
            // CC1: the version the caller read is compared on the locked row.
            $this->versions->assertExpected($current, 'version', $expectedVersion, WebhookEndpoint::AGGREGATE);
            $this->endpoints->rotateSecret($current, $secret, $overlap === 0 ? null : now()->addSeconds($overlap));
            $this->versions->bump($current, 'version', WebhookEndpoint::AGGREGATE);
            $endpoint->setRawAttributes($current->getAttributes(), sync: true);

            return new RotatedWebhookSecret(endpointId: $endpoint->id, secret: $secret);
        });
    }
}
