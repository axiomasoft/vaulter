<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\Webhooks\WebhookFanoutConsumer;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The webhooks.fanout outbox consumer (D29), moved from the 0.x OutboxRelay: an external event becomes one
 * WebhookDelivery per eligible endpoint, chunk by chunk, each chunk fenced by the outbox delivery lease and
 * resumed from its continuation; the delivery row is done only after the last chunk. Chunks of 2 over 5
 * endpoints give three fan-out transactions.
 *
 * A crash is simulated by throwing from the TransactionCommitted listener: the transaction is durable, and
 * nothing after it runs.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('vaulter.webhooks.relay.fanout_chunk', 2);
    config()->set('vaulter.outbox.lease_seconds', 60);
});

/** @return list<WebhookEndpoint> */
function fanoutEndpoints(int $count = 5): array
{
    $endpoints = [];
    for ($index = 0; $index < $count; $index++) {
        $endpoints[] = WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/'.$index]);
    }

    return $endpoints;
}

/** Throws right after the $nth commit from now on. */
function crashAfterCommit(int $nth): void
{
    $commits = 0;
    Event::listen(TransactionCommitted::class, static function () use (&$commits, $nth): void {
        if (++$commits === $nth) {
            throw new RuntimeException('relay crashed');
        }
    });
}

function stopCrashing(): void
{
    Event::forget(TransactionCommitted::class);
}

/** @return list<string> endpoint ids with a delivery of $message, sorted */
function deliveredEndpoints(OutboxEvent $message): array
{
    return WebhookDelivery::query()->where('event_id', $message->event_id)->pluck('endpoint_id')
        ->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all();
}

/** @param list<WebhookEndpoint> $endpoints */
function endpointIds(array $endpoints): array
{
    return collect($endpoints)->map(static fn (WebhookEndpoint $endpoint): string => (string) $endpoint->getKey())->sort()->values()->all();
}

/** The fanout delivery row of $message. */
function fanoutRow(OutboxEvent $message): array
{
    return WebhookTestHelpers::outboxDelivery($message->event_id) ?? throw new RuntimeException('no delivery row');
}

/** Worker A: leases the fanout delivery of $message as a relay pass would and returns its generation. */
function leaseFanout(OutboxEvent $message): int
{
    $claims = app(OutboxStore::class)->claim(WebhookFanoutConsumer::ID, 'worker-a', CarbonImmutable::now(), 60, 10);
    expect(array_column($claims, 'event_id'))->toBe([$message->event_id]);

    return $claims[0]['generation'];
}

/** Consume $message directly under the lease generation $generation. */
function consumeWithGeneration(OutboxEvent $message, int $generation): mixed
{
    $control = new DeliveryControl(app(VaulterDatabase::class), app(DatabaseConfig::class), $message->event_id, WebhookFanoutConsumer::ID, $generation, fanoutRow($message)['continuation']);

    return app(WebhookFanoutConsumer::class)->consume(OutboxEnvelope::fromModel($message), $control);
}

it('sends the same occurrence id on the original delivery and a retry', function (): void {
    Bus::fake();
    Http::fake(['https://hooks.test/*' => Http::sequence()->push('', 500)->push('', 200)]);

    WebhookTestHelpers::endpoint();
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'Retry');
    $event = new FolderCreated(node: $node, actor: new ActorRef(type: 'user', id: 7), correlationId: (string) Str::ulid());
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event));
    $this->travel(1)->seconds();

    WebhookTestHelpers::relay();
    $delivery = WebhookDelivery::query()->where('event_id', $event->eventId)->sole();
    $job = new DeliverWebhook(deliveryId: (string) $delivery->getKey());
    $job->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), vaulterConfig: app(VaulterConfig::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
    expect($delivery->refresh()->status)->toBe('failed');

    $delivery->forceFill(['next_retry_at' => now()->subSecond()])->save();
    $this->artisan('vaulter:webhooks:retry')->assertSuccessful();
    Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $retry): bool => new ReflectionProperty($retry, 'deliveryId')->getValue($retry) === (string) $delivery->getKey());
    $job->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), vaulterConfig: app(VaulterConfig::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));

    expect($delivery->refresh()->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(2)
        ->and(WebhookDelivery::query()->where('event_id', $event->eventId)->count())->toBe(1);
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->data()['id'])->toBe($event->eventId);
    }
});

it('fans an event out to every eligible endpoint and finishes its delivery row', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    expect(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 1]);

    $row = fanoutRow($message);
    expect(deliveredEndpoints($message))->toBe(endpointIds($endpoints))
        ->and($row['state'])->toBe('done')
        ->and((int) $row['lease_generation'])->toBe(1)
        ->and($row['lease_owner'])->toBeNull()
        ->and($row['continuation'])->toBe((string) $endpoints[4]->getKey());
    $first = WebhookDelivery::query()->where('event_id', $message->event_id)->orderBy('id')->first();
    expect($first->event)->toBe('folder.created')
        ->and($first->status)->toBe('pending')
        ->and($first->attempts)->toBe(0)
        ->and($first->payload['node_id'])->toBe('node-1');
    Queue::assertPushed(DeliverWebhook::class, 5);
});

it('is idempotent: a second pass does not fan a finished event out again', function (): void {
    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox();

    expect(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 1])
        ->and(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 0])
        ->and(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('does not fan out twice when a second relay pass starts during the first', function (): void {
    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox();

    $overlap = null;
    DB::listen(static function ($query) use (&$overlap): void {
        if ($overlap === null && str_contains((string) $query->sql, 'webhook_endpoints')) {
            $overlap = WebhookTestHelpers::relay();
        }
    });

    expect(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 1])
        ->and($overlap)->toBe([WebhookFanoutConsumer::ID => 0])
        ->and(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('skips endpoints not subscribed to the type and inactive ones', function (): void {
    $a = WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    $b = WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook', 'events' => ['folder.created', 'share.created']]);
    WebhookTestHelpers::endpoint(['url' => 'https://c.test/hook', 'events' => ['share.created']]);
    WebhookTestHelpers::endpoint(['url' => 'https://d.test/hook', 'is_active' => false]);
    $message = WebhookTestHelpers::outbox();

    WebhookTestHelpers::relay();

    expect(deliveredEndpoints($message))->toBe(endpointIds([$a, $b]))
        ->and(fanoutRow($message)['state'])->toBe('done');
    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('finishes an event nobody subscribed to without a delivery', function (): void {
    WebhookTestHelpers::endpoint(['events' => ['share.created']]);
    $message = WebhookTestHelpers::outbox();

    WebhookTestHelpers::relay();

    expect(fanoutRow($message)['state'])->toBe('done')
        ->and(fanoutRow($message)['continuation'])->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('finishes an occurrence that is not external without reaching any endpoint', function (): void {
    WebhookTestHelpers::endpoint(['events' => ['document.updated']]);
    $message = WebhookTestHelpers::outbox(['type' => 'document.updated', 'external' => false]);

    WebhookTestHelpers::relay();

    expect(fanoutRow($message)['state'])->toBe('done')
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

it('filters subscriptions in SQL and fans out in bounded key-ordered pages', function (): void {
    config()->set('vaulter.webhooks.relay.fanout_chunk', 100);
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
        if (str_contains((string) $query->sql, 'webhook_endpoints')) {
            $endpointQueries[] = $query->sql;
        }
    });

    WebhookTestHelpers::relay();

    expect(WebhookDelivery::query()->orderBy('id')->pluck('endpoint_id')->all())->toBe($matchingIds)
        ->and(count($endpointQueries))->toBeGreaterThanOrEqual(3);
    foreach ($endpointQueries as $sql) {
        expect($sql)->toContain('events');
    }
    Queue::assertPushed(DeliverWebhook::class, 205);
});

it('resumes from the continuation after the consumer dies between chunks, without duplicates', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    // Worker A leases the delivery and dies right after its first chunk committed.
    $generation = leaseFanout($message);
    crashAfterCommit(1);
    expect(fn () => consumeWithGeneration($message, $generation))->toThrow(RuntimeException::class, 'relay crashed');
    stopCrashing();

    $row = fanoutRow($message);
    expect($row['state'])->toBe('leased')
        ->and($row['continuation'])->toBe((string) $endpoints[1]->getKey())
        ->and(deliveredEndpoints($message))->toBe(endpointIds(array_slice($endpoints, 0, 2)));

    // The lease is still live: nobody takes the delivery over yet.
    expect(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 0]);

    $this->travel(61)->seconds();
    expect(WebhookTestHelpers::relay())->toBe([WebhookFanoutConsumer::ID => 1]);

    expect(fanoutRow($message)['state'])->toBe('done')
        ->and((int) fanoutRow($message)['lease_generation'])->toBe(2)
        ->and(WebhookDelivery::query()->count())->toBe(5)
        ->and(deliveredEndpoints($message))->toBe(endpointIds($endpoints));
    Queue::assertPushed(DeliverWebhook::class, 5);
});

it('discards the chunk of a consumer whose lease was taken over', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    // Worker A writes its first chunk under generation 1, then stalls past its lease.
    $generation = leaseFanout($message);
    crashAfterCommit(1);
    expect(fn () => consumeWithGeneration($message, $generation))->toThrow(RuntimeException::class);
    stopCrashing();

    // Worker B takes the delivery over (generation 2) and finishes it.
    $this->travel(61)->seconds();
    WebhookTestHelpers::relay();
    expect(fanoutRow($message)['state'])->toBe('done');

    // A wakes up and runs its next chunk with generation 1: nothing is written.
    $result = consumeWithGeneration($message, $generation);

    expect($result->outcome)->toBe('retry')
        ->and(WebhookDelivery::query()->count())->toBe(5)
        ->and(deliveredEndpoints($message))->toBe(endpointIds($endpoints));
});

it('does not let a stale generation write into a delivery another worker holds', function (): void {
    fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    // Worker A holds the lease (generation 1); a writer with another generation is refused.
    leaseFanout($message);

    expect(consumeWithGeneration($message, 7)->outcome)->toBe('retry')
        ->and(WebhookDelivery::query()->count())->toBe(0)
        ->and(fanoutRow($message)['continuation'])->toBeNull();
});

it('finishes an event whose eligible endpoints fill the last chunk exactly', function (): void {
    fanoutEndpoints(4);
    $message = WebhookTestHelpers::outbox();

    WebhookTestHelpers::relay();

    expect(fanoutRow($message)['state'])->toBe('done')
        ->and(WebhookDelivery::query()->count())->toBe(4);
});

it('does not deliver an event to an endpoint registered after it happened', function (): void {
    $before = WebhookTestHelpers::endpoint();
    $message = WebhookTestHelpers::outbox();
    $this->travel(5)->seconds();
    WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/late']);

    WebhookTestHelpers::relay();

    expect(deliveredEndpoints($message))->toBe([(string) $before->getKey()]);
});

it('leaves out an endpoint deactivated while the event is being fanned out', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    $generation = leaseFanout($message);
    crashAfterCommit(1);
    expect(fn () => consumeWithGeneration($message, $generation))->toThrow(RuntimeException::class);
    stopCrashing();

    $endpoints[3]->forceFill(['is_active' => false])->save();
    $this->travel(61)->seconds();
    WebhookTestHelpers::relay();

    expect(fanoutRow($message)['state'])->toBe('done')
        ->and(deliveredEndpoints($message))->toBe(endpointIds([$endpoints[0], $endpoints[1], $endpoints[2], $endpoints[4]]));
});

it('delivers due rows oldest first, honours the limit and skips rows under a live lease or not yet due', function (): void {
    WebhookTestHelpers::endpoint();
    $later = WebhookTestHelpers::outbox(['created_at' => now()->subSecond()]);
    $earlier = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(5)]);
    $future = WebhookTestHelpers::outbox(['created_at' => now()->addHour()]);

    expect(WebhookTestHelpers::relay(limit: 1))->toBe([WebhookFanoutConsumer::ID => 1])
        ->and(fanoutRow($earlier)['state'])->toBe('done')
        ->and(fanoutRow($later)['state'])->toBe('pending')
        ->and(fanoutRow($future)['state'])->toBe('pending');

    $this->artisan('vaulter:relay', ['--once' => true])->assertSuccessful();
    expect(fanoutRow($later)['state'])->toBe('done')
        ->and(fanoutRow($future)['state'])->toBe('pending');
});

it('rejects a fan-out chunk outside 1..1000 and retries the delivery later', function (mixed $chunk): void {
    config()->set('vaulter.webhooks.relay.fanout_chunk', $chunk);
    WebhookTestHelpers::endpoint();
    $message = WebhookTestHelpers::outbox();

    expect(fn () => app(WebhooksConfig::class)->relayFanoutChunk())->toThrow(InvalidConfigurationException::class);
    WebhookTestHelpers::relay();

    expect(fanoutRow($message)['state'])->toBe('pending')
        ->and((int) fanoutRow($message)['attempts'])->toBe(1)
        ->and(fanoutRow($message)['last_error'])->toContain(InvalidConfigurationException::class);
})->with([0, 1001, 'many', 2.5]);

it('re-dispatches only due failed webhook deliveries with vaulter:webhooks:retry', function (): void {
    Bus::fake();
    $endpoint = WebhookTestHelpers::endpoint();
    $due = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => now()->subMinute()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => now()->addHour()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5, 'next_retry_at' => now()->subMinute()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1, 'next_retry_at' => now()->subMinute()]);

    $this->artisan('vaulter:webhooks:retry')->assertSuccessful();

    Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job): bool => new ReflectionProperty($job, 'deliveryId')->getValue($job) === (string) $due->getKey());
    Bus::assertDispatched(DeliverWebhook::class, 1);
});
