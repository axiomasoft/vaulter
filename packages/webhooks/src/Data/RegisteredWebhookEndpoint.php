<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Data;

use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The result of registering a webhook endpoint: the
 * persisted endpoint plus the raw signing_secret returned to the caller EXACTLY ONCE.
 * The secret is what the receiver verifies HMAC signatures with; the resource never
 * re-serializes it from the stored row, so this is the single moment it is visible.
 */
#[Internal]
final readonly class RegisteredWebhookEndpoint
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $signingSecret,
    ) {}
}
