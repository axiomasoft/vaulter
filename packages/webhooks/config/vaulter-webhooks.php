<?php

declare(strict_types=1);

use Vaulter\Webhooks\DefaultOutboundUrlGuard;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/** Publish with the vaulter-webhooks-config tag. */
return [
    'timeout' => 15,
    // Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three
    // days in total). Jitter is the +/- fraction applied to each delay.
    'retry_schedule' => [5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400],
    'retry_jitter' => 0.1,
    // Days of uninterrupted failure before an endpoint is disabled; 0 never disables.
    'disable_after_days' => 5,
    // Seconds the previous secret keeps signing after a rotation (max 604800).
    'secret_rotation_overlap' => 86400,
    'retention_days' => 30,
    'retry_claim_seconds' => 60,
    // The webhooks.fanout outbox consumer materializes deliveries fanout_chunk endpoints at a
    // time (1..1000), each chunk in its own transaction under the outbox delivery lease.
    'relay' => [
        'fanout_chunk' => 100,
    ],
    // This ability authorizes management; strict tenancy still limits it to the current tenant's endpoints.
    // Endpoints without a tenant need an explicit administrative migration before a tenant can manage them.
    'manage_ability' => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
    'delivery' => [
        'url_guard' => DefaultOutboundUrlGuard::class,
        'allowed_schemes' => ['http', 'https'],
        'blocked_ip_ranges' => null,
        'allowed_ip_ranges' => [],
        'connect_timeout' => 2,
        'max_response_bytes' => 65536,
    ],
    // Same value grammar and vaulter.schedule.enabled switch as the core schedule keys.
    'schedule' => [
        'webhooks_retry' => 'everyMinute',
        'webhooks_prune' => 'dailyAt:03:10',
    ],
    'models' => [
        'webhook_endpoint' => WebhookEndpoint::class,
        'webhook_delivery' => WebhookDelivery::class,
    ],
];
