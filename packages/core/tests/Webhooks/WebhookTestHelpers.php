<?php

declare(strict_types=1);

namespace Vaulter\Tests\Webhooks;

use Illuminate\Support\Str;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * Row builders for the M3b reliability tests (§2.11): stage outbox rows, register
 * endpoints, and seed deliveries directly so the tests focus on the relay / delivery
 * algorithm, not on row plumbing.
 */
final class WebhookTestHelpers
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function outbox(array $attributes = []): Outbox
    {
        return Outbox::query()->create(array_merge([
            'event_id' => 'evt-'.Str::uuid7()->toString(),
            'panel' => 'default',
            'topic' => 'node-1',
            'event' => 'node.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'available_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create(array_merge([
            'url' => 'https://hooks.test/receive',
            'events' => ['node.created'],
            'signing_secret' => 'topsecret',
            'is_active' => true,
            'failure_count' => 0,
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function delivery(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
    {
        return WebhookDelivery::query()->create(array_merge([
            'endpoint_id' => $endpoint->getKey(),
            'event' => 'node.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
        ], $attributes));
    }
}
