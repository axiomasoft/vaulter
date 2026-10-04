<?php

declare(strict_types=1);

namespace Vaulter\Actions\Webhooks;

use Illuminate\Support\Str;
use Vaulter\Data\Webhooks\RegisteredWebhookEndpoint;
use Vaulter\Data\Webhooks\RegisterWebhookEndpointInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Repositories\Webhooks\WebhookEndpointStoreRepository;

/**
 * Register a webhook endpoint (master plan §2.11 / D14 / M3b). It GENERATES the
 * signing_secret here — a 64-char random string the receiver verifies HMAC-SHA256
 * signatures with — persists it, and returns the raw secret ONCE via the result. The
 * resource never re-exposes it, so it is unrecoverable afterward (the owner must
 * rotate by re-registering to get a new one).
 *
 * Authorization (the actor may manage webhooks) is enforced by the caller before the
 * Action runs — the Action persists, it does not re-authorize.
 */
final readonly class RegisterWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointStoreRepository $endpoints,
        private VaulterDatabase $database,
    ) {}

    public function execute(RegisterWebhookEndpointInput $data): RegisteredWebhookEndpoint
    {
        $secret = $this->generateSecret();

        return $this->database->transaction(function () use ($data, $secret): RegisteredWebhookEndpoint {
            $endpoint = $this->endpoints->create(
                url: $data->url,
                events: $data->events,
                signingSecret: $secret,
                isActive: $data->isActive,
            );

            return new RegisteredWebhookEndpoint(endpoint: $endpoint, signingSecret: $secret);
        });
    }

    /**
     * A 64-char (256-bit) random hex secret — ample entropy for an HMAC key, and a
     * fixed length the signing_secret column always fits.
     */
    private function generateSecret(): string
    {
        return Str::random(64);
    }
}
