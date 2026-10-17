<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Data;

use Vaulter\Attributes\Internal;

/**
 * The command DTO for registering a webhook endpoint.
 * Carries the receiver URL and the list of subscribed event names (validated against
 * the event catalogue by the request). The signing_secret is NOT part of this
 * command — it is generated inside the Action and returned ONCE; only its stored form
 * lives on the row. driveId, when set, limits the endpoint to that drive's events;
 * the drive must be visible in the current tenant.
 *
 * Built from a validated request and consumed ONLY by
 * {@see RegisterWebhookEndpointAction}.
 */
#[Internal]
final readonly class RegisterWebhookEndpointInput
{
    /**
     * @param list<string> $events
     */
    public function __construct(
        public string $url,
        public array $events,
        public bool $isActive = true,
        public ?string $driveId = null,
    ) {}
}
