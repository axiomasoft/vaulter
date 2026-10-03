<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Drive\Events\NodeCreated;
use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRecorder;
use Vaulter\Webhooks\OutboxRelay;
use Vaulter\Webhooks\WebhookSigner;

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

it('sends the same occurrence id on the original delivery and a relay retry', function (): void {
    Queue::fake();
    Bus::fake();
    Http::fake(['https://hooks.test/*' => Http::sequence()->push('', 500)->push('', 200)]);

    WebhookTestHelpers::endpoint();
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'Retry');
    Outbox::query()->delete();
    $event = new NodeCreated(node: $node, actorId: 7);
    $message = app(OutboxRecorder::class)->stage(
        event: WebhookEvent::NodeCreated,
        eventId: $event->eventId,
        panel: $node->panel,
        topic: (string) $node->getKey(),
        payload: ['node_id' => (string) $node->getKey()],
        actorId: 7,
    );

    expect(relay()->process())->toBe(1);
    $delivery = WebhookDelivery::query()->sole();
    $job = new DeliverWebhook(deliveryId: (string) $delivery->getKey());
    $job->handle(app(WebhookSigner::class), app(OutboundUrlGuard::class));
    $delivery->refresh();
    expect($delivery->status)->toBe('failed');

    $delivery->forceFill(['next_retry_at' => now()->subSecond()])->save();
    $this->artisan('vaulter:relay-outbox')->assertSuccessful();
    Bus::assertDispatched(DeliverWebhook::class, function (DeliverWebhook $retry) use ($delivery): bool {
        return (new ReflectionProperty($retry, 'deliveryId'))->getValue($retry) === (string) $delivery->getKey();
    });
    $job->handle(app(WebhookSigner::class), app(OutboundUrlGuard::class));

    expect($delivery->refresh()->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(2)
        ->and($message->refresh()->event_id)->toBe($event->eventId)
        ->and(WebhookDelivery::query()->count())->toBe(1);
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->data()['event_id'])->toBe($event->eventId);
    }
});

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

it('does not double fan out when a second relay tick starts after the first claim', function (): void {
    Queue::fake();

    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox();

    $overlapResult = null;
    DB::listen(static function ($query) use (&$overlapResult): void {
        if ($overlapResult === null && str_contains($query->sql, 'webhook_endpoints')) {
            $overlapResult = relay()->process();
        }
    });

    expect(relay()->process())->toBe(1)
        ->and($overlapResult)->toBe(0)
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

it('filters subscriptions in SQL and fans out in bounded key-ordered pages', function (): void {
    Queue::fake();

    $matchingIds = [];

    for ($index = 0; $index < 205; $index++) {
        $matchingIds[] = WebhookTestHelpers::endpoint()->getKey();
    }

    for ($index = 0; $index < 120; $index++) {
        WebhookTestHelpers::endpoint(['events' => ['share.created']]);
    }

    WebhookTestHelpers::endpoint(['is_active' => false]);
    WebhookTestHelpers::outbox();

    $endpointQueries = [];
    DB::listen(static function ($query) use (&$endpointQueries): void {
        if (str_contains($query->sql, 'webhook_endpoints')) {
            $endpointQueries[] = $query->sql;
        }
    });

    expect(relay()->process())->toBe(1);

    $actualIds = WebhookDelivery::query()->orderBy('id')->pluck('endpoint_id')->all();
    expect($actualIds)->toBe($matchingIds)
        ->and(count($endpointQueries))->toBeGreaterThanOrEqual(3);

    foreach ($endpointQueries as $sql) {
        expect($sql)->toContain('events');
    }

    Queue::assertPushed(DeliverWebhook::class, 205);
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
