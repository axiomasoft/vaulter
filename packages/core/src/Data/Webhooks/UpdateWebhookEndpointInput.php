<?php

declare(strict_types=1);

namespace Vaulter\Data\Webhooks;

/**
 * The command DTO for updating a webhook endpoint.
 * Each field is nullable so a PATCH may touch only what it sends — a null field is
 * left unchanged by the Action. The signing_secret is NEVER updatable through this
 * command (it is shown once at registration and never re-serialized). A non-null
 * driveId moves the endpoint's drive filter to that drive; a null one keeps it.
 *
 * Built from a validated request and consumed ONLY by
 * {@see UpdateWebhookEndpointAction}.
 */
final readonly class UpdateWebhookEndpointInput
{
    /**
     * @param list<string>|null $events
     */
    public function __construct(
        public ?string $url = null,
        public ?array $events = null,
        public ?bool $isActive = null,
        public ?string $driveId = null,
    ) {}
}
