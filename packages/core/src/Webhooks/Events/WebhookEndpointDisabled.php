<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Events;

use Illuminate\Support\Carbon;

/**
 * Operational Laravel event, dispatched after the commit that disabled an endpoint
 * because its deliveries kept failing. It is not a domain event (no EventType, no
 * outbox row): hosts listen to it to notify the endpoint's owner.
 */
final readonly class WebhookEndpointDisabled
{
    public function __construct(
        public int|string $endpointId,
        public string $reason,
        public ?Carbon $failingSince,
    ) {}
}
