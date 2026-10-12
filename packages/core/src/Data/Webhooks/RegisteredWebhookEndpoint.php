<?php

declare(strict_types=1);

namespace Vaulter\Data\Webhooks;

use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * The result of registering a webhook endpoint: the
 * persisted endpoint plus the raw signing_secret returned to the caller EXACTLY ONCE.
 * The secret is what the receiver verifies HMAC signatures with; the resource never
 * re-serializes it from the stored row, so this is the single moment it is visible.
 */
final readonly class RegisteredWebhookEndpoint
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $signingSecret,
    ) {}
}
