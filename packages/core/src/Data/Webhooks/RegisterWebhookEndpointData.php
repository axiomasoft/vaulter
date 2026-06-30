<?php

declare(strict_types=1);

namespace Vaulter\Data\Webhooks;

use Vaulter\Actions\Webhooks\RegisterWebhookEndpointAction;

/**
 * The command DTO for registering a webhook endpoint (master plan §2.11 / D14 / M3b).
 * Carries the receiver URL and the list of subscribed event names (validated against
 * the WebhookEvent enum by the request). The signing_secret is NOT part of this
 * command — it is generated inside the Action and returned ONCE; only its stored form
 * lives on the row.
 *
 * Built from a validated request and consumed ONLY by
 * {@see RegisterWebhookEndpointAction}.
 */
final readonly class RegisterWebhookEndpointData
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $url,
        public array $events,
        public bool $isActive = true,
    ) {}
}
