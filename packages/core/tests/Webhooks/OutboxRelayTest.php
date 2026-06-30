<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;

/**
 * The transactional-outbox RELAY (§2.11 / D14 / M3b), ported from chatom: process()
 * fans pending outbox rows out into per-endpoint WebhookDelivery rows + DeliverWebhook
 * jobs; vaulter:relay-outbox ticks and re-arms due retries. Idempotency is row-state
 * driven (pending → dispatched), so overlapping ticks never double-deliver.
 *
 * Jobs are faked (Queue/Bus::fake) so dispatch is asserted without the HTTP job.
 */
function relay(): OutboxRelay
{
    return app(OutboxRelay::class);
}

// ─────────────────────── process() — fan-out ───────────────────────

it('fans a pending outbox row out to a subscribed active endpoint and dispatches its job', function (): void {
    Queue::fake();

    $endpoint = WebhookTestHelpers::endpoint();
    $message = WebhookTestHelpers::outbox();

    $processed = relay()->process();

    expect($processed)->toBe(1);

    $deliveries = WebhookDelivery::query()->get();

    expect($deliveries)->toHaveCount(1)
        ->and((string) $deliveries->first()->endpoint_id)->toBe((string) $endpoint->getKey())
        ->and($deliveries->first()->event)->toBe('node.created')
        ->and($deliveries->first()->status)->toBe('pending')
        ->and($deliveries->first()->attempts)->toBe(0)
        ->and($deliveries->first()->payload['node_id'])->toBe('node-1');

    $message->refresh();

    expect($message->status)->toBe('dispatched')
        ->and($message->dispatched_at)->toBeInstanceOf(Carbon::class);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('is idempotent: a second process() does not re-fan an already-dispatched row', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox();

    $first = relay()->process();
    $second = relay()->process();

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(1);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('does not create a delivery for an endpoint not subscribed to the event', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint(['events' => ['share.created']]);
    WebhookTestHelpers::outbox(['event' => 'node.created']);

    $processed = relay()->process();

    expect($processed)->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('does not create a delivery for an inactive endpoint', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint(['is_active' => false]);
    WebhookTestHelpers::outbox();

    $processed = relay()->process();

    expect($processed)->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('fans out to every subscribed active endpoint and skips the unsubscribed/inactive', function (): void {
    Queue::fake();

    $a = WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    $b = WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook', 'events' => ['node.created', 'share.created']]);
    WebhookTestHelpers::endpoint(['url' => 'https://c.test/hook', 'events' => ['share.created']]); // unsubscribed
    WebhookTestHelpers::endpoint(['url' => 'https://d.test/hook', 'is_active' => false]);          // inactive

    WebhookTestHelpers::outbox(['event' => 'node.created']);

    relay()->process();

    $endpointIds = WebhookDelivery::query()->pluck('endpoint_id')->map(fn ($id): string => (string) $id);

    expect(WebhookDelivery::query()->count())->toBe(2)
        ->and($endpointIds)->toContain((string) $a->getKey())
        ->and($endpointIds)->toContain((string) $b->getKey());

    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('leaves a not-yet-available outbox row pending', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox(['available_at' => now()->addHour()]);

    expect(relay()->process())->toBe(0)
        ->and(Outbox::query()->where('status', 'pending')->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

// ─────────────────────── vaulter:relay-outbox ───────────────────────

it('vaulter:relay-outbox drains a pending outbox row into a delivery and a job', function (): void {
    Bus::fake();

    WebhookTestHelpers::endpoint();
    $message = WebhookTestHelpers::outbox();

    $this->artisan('vaulter:relay-outbox')->assertSuccessful();

    expect($message->refresh()->status)->toBe('dispatched')
        ->and(WebhookDelivery::query()->where('status', 'pending')->count())->toBe(1);

    Bus::assertDispatched(DeliverWebhook::class, 1);
});

it('vaulter:relay-outbox re-dispatches a due failed retry but skips a not-yet-due one', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();

    $due = WebhookTestHelpers::delivery($endpoint, [
        'status' => 'failed',
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
    ]);

    WebhookTestHelpers::delivery($endpoint, [
        'status' => 'failed',
        'attempts' => 1,
        'next_retry_at' => now()->addHour(),
    ]);

    $this->artisan('vaulter:relay-outbox')->assertSuccessful();

    Bus::assertDispatched(DeliverWebhook::class, function (DeliverWebhook $job) use ($due): bool {
        return (new ReflectionProperty($job, 'deliveryId'))->getValue($job) === (string) $due->getKey();
    });
    Bus::assertDispatched(DeliverWebhook::class, 1);
});

it('vaulter:relay-outbox does not re-dispatch terminal deliveries', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();

    WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1, 'next_retry_at' => now()->subMinute()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5, 'next_retry_at' => now()->subMinute()]);

    $this->artisan('vaulter:relay-outbox')->assertSuccessful();

    Bus::assertNotDispatched(DeliverWebhook::class);
});

it('vaulter:relay-outbox honours the --limit option', function (): void {
    Bus::fake();

    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox(['event_id' => 'a', 'available_at' => now()->subSeconds(2)]);
    WebhookTestHelpers::outbox(['event_id' => 'b', 'available_at' => now()->subSecond()]);

    $this->artisan('vaulter:relay-outbox', ['--limit' => 1])->assertSuccessful();

    expect(Outbox::query()->where('status', 'pending')->count())->toBe(1)
        ->and(Outbox::query()->where('status', 'dispatched')->count())->toBe(1);

    Bus::assertDispatched(DeliverWebhook::class, 1);
});

// ─────────────────────── vaulter:retry-webhooks ───────────────────────

it('vaulter:retry-webhooks re-queues only due failed deliveries', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();

    $due = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => now()->subMinute()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => now()->addHour()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5, 'next_retry_at' => now()->subMinute()]);

    $this->artisan('vaulter:retry-webhooks')->assertSuccessful();

    Bus::assertDispatched(DeliverWebhook::class, function (DeliverWebhook $job) use ($due): bool {
        return (new ReflectionProperty($job, 'deliveryId'))->getValue($job) === (string) $due->getKey();
    });
    Bus::assertDispatched(DeliverWebhook::class, 1);
});
