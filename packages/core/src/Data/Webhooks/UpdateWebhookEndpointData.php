<?php

declare(strict_types=1);

namespace Vaulter\Data\Webhooks;

use Vaulter\Actions\Webhooks\UpdateWebhookEndpointAction;

/**
 * The command DTO for updating a webhook endpoint (master plan §2.11 / D14 / M3b).
 * Each field is nullable so a PATCH may touch only what it sends — a null field is
 * left unchanged by the Action. The signing_secret is NEVER updatable through this
 * command (it is shown once at registration and never re-serialized).
 *
 * Built from a validated request and consumed ONLY by
 * {@see UpdateWebhookEndpointAction}.
 */
final readonly class UpdateWebhookEndpointData
{
    /**
     * @param list<string>|null $events
     */
    public function __construct(
        public ?string $url = null,
        public ?array $events = null,
        public ?bool $isActive = null,
    ) {}
}
