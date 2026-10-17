<?php

declare(strict_types=1);

use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;

/** Publish with the vaulter-config tag. */
return [
    'database' => [
        'connection' => env('VAULTER_DB_CONNECTION'),
        'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
        // Refuse writes to Vaulter models outside the Vaulter operations (defence in depth, not a sandbox).
        'write_guard' => env('VAULTER_DB_WRITE_GUARD', true),
    ],
    'ids' => [
        'strategy' => env('VAULTER_ID_STRATEGY', 'ulid'),
        'host_keys' => env('VAULTER_HOST_KEYS', 'string'),
        'strategies' => [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ],
    ],
    'tenancy' => ['enabled' => env('VAULTER_TENANT_ENABLED', false), 'resolver' => null],
    // Module activation overrides (D22): module id => true|false. Auto modules (media, drive, documents…)
    // are active unless switched off; opt-in modules (webhooks) only when switched on.
    'modules' => [],
    'routes' => [
        'prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
        'middleware' => [
            'authenticated' => ['api', 'auth:sanctum'],
            'public' => ['api'],
            'hub' => ['api'],
        ],
        'rate_limits' => [
            'public' => env('VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE', 60),
            'uploads' => env('VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE', 120),
        ],
    ],
    'webhooks' => [
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
        'manage_ability' => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
        'delivery' => [
            'url_guard' => DefaultOutboundUrlGuard::class,
            'allowed_schemes' => ['http', 'https'],
            'blocked_ip_ranges' => null,
            'allowed_ip_ranges' => [],
            'connect_timeout' => 2,
            'max_response_bytes' => 65536,
        ],
    ],
    // The transactional outbox (D29): every domain event is written to v_outbox in the transaction of its
    // operation; vaulter:relay hands it to each outbox consumer (webhooks.fanout, …) at least once.
    // settle_window_seconds must exceed the longest Vaulter transaction: reconciliation only trusts events
    // older than it, so a long transaction committing a smaller seq later is not skipped.
    'outbox' => [
        'settle_window_seconds' => 300,
        'retention_seconds' => 604800,
        // How long a paused consumer (switched off, missing from the module graph) holds retention before it
        // is detached with an explicit gap.
        'max_consumer_hold_seconds' => 604800,
        'dead_retention_seconds' => 2592000,
        'lease_seconds' => 60,
        'batch' => 100,
        'max_attempts' => 10,
        'backoff_base_seconds' => 2,
        'backoff_cap_seconds' => 3600,
    ],
    // Idempotency-Key receipts (D25): how long a retry with the same key gets the stored outcome.
    // vaulter:idempotency:prune deletes expired receipts.
    'idempotency' => [
        'ttl_seconds' => 86400,
    ],
    // Maintenance tasks put on the host scheduler. Value: null (off) | everyMinute | everyFiveMinutes |
    // hourly | daily | weekly | 'dailyAt:HH:MM' | 'hourlyAt:M' | 'weeklyOn:D,HH:MM' | a 5-field cron
    // expression. Set enabled to false when the host schedules the commands itself.
    'schedule' => [
        'enabled' => env('VAULTER_SCHEDULE_ENABLED', true),
        'outbox_relay' => 'everyMinute',
        'outbox_prune' => 'hourly',
        'webhooks_retry' => 'everyMinute',
        'webhooks_prune' => 'dailyAt:03:10',
        'idempotency_prune' => 'dailyAt:03:30',
    ],
    'observability' => [
        'enabled' => env('VAULTER_OBSERVABILITY_ENABLED', false),
        'recorder' => env('VAULTER_OBSERVABILITY_RECORDER'),
        'tracing' => [
            'enabled' => env('VAULTER_TRACING_ENABLED', false),
            'recorder' => env('VAULTER_TRACING_RECORDER'),
        ],
    ],
    'models' => [
        'outbox' => OutboxEvent::class,
        'webhook_endpoint' => WebhookEndpoint::class,
        'webhook_delivery' => WebhookDelivery::class,
    ],
];
