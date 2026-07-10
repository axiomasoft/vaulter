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
        // forceFill (P1.4/F-P1.8-02): Outbox is fully guarded — this trusted
        // fixture builder bypasses it exactly like OutboxRecorder::stage() does.
        $outbox = new Outbox;
        $outbox->forceFill(array_merge([
            'event_id' => 'evt-'.Str::uuid7()->toString(),
            'panel' => 'default',
            'topic' => 'node-1',
            'event' => 'node.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'available_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
        ], $attributes));
        $outbox->save();

        return $outbox;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function endpoint(array $attributes = []): WebhookEndpoint
    {
        // forceFill (P1.4/F-P1.7-04): signing_secret/failure_count are excluded
        // from WebhookEndpoint's $fillable — bypassed here exactly like
        // WebhookEndpointStoreRepository::create() does.
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill(array_merge([
            'url' => 'https://hooks.test/receive',
            'events' => ['node.created'],
            'signing_secret' => 'topsecret',
            'is_active' => true,
            'failure_count' => 0,
        ], $attributes));
        $endpoint->save();

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function delivery(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
    {
        // forceFill (P1.4/F-P1.7-04): WebhookDelivery is fully guarded —
        // bypassed here exactly like OutboxRelay::createDelivery() does.
        $delivery = new WebhookDelivery;
        $delivery->forceFill(array_merge([
            'endpoint_id' => $endpoint->getKey(),
            'event' => 'node.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
        ], $attributes));
        $delivery->save();

        return $delivery;
    }
}
