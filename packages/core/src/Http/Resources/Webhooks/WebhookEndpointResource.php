<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * The read shape of a webhook endpoint (master plan §2.11 / D14 / M3b). It NEVER
 * serializes the signing_secret from the stored row: the secret is surfaced EXACTLY
 * ONCE, at registration, via {@see self::withSecret()}. Every list/show response omits
 * it (it is unrecoverable from the row, so the owner rotates by re-registering).
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends JsonResource
{
    /**
     * The raw signing_secret, attached ONLY by the register endpoint so it is returned
     * once. Null on every list/show response.
     */
    private ?string $signingSecret = null;

    /**
     * Attach the raw signing_secret to this (register-time only) response — the single
     * moment it is ever visible.
     */
    public function withSecret(string $secret): self
    {
        $this->signingSecret = $secret;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        $payload = [
            'id' => $endpoint->getKey(),
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
            'failure_count' => $endpoint->failure_count,
            'last_triggered_at' => $endpoint->last_triggered_at,
            'created_at' => $endpoint->created_at,
            'updated_at' => $endpoint->updated_at,
        ];

        // The raw secret is present ONLY in the register response (returned once).
        if ($this->signingSecret !== null) {
            $payload['signing_secret'] = $this->signingSecret;
        }

        return $payload;
    }
}
