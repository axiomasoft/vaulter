<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;

/**
 * Recoverable fan-out: a row is 'completed' only in the transaction that writes its
 * last deliveries, a crashed relay's row is resumed after its lease from the fan-out
 * cursor, and a relay whose lease was taken over writes nothing. Chunks of 2 over 5
 * endpoints give three fan-out transactions per row.
 *
 * A crash is simulated by throwing from the TransactionCommitted listener: the
 * transaction is durable, and nothing after it runs.
 */
beforeEach(function (): void {
    Queue::fake();
    config()->set('vaulter.webhooks.relay.fanout_chunk', 2);
    config()->set('vaulter.webhooks.relay.claim_seconds', 60);
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

/** Throws right after the $nth relay commit (1 = the claim, 2 = the first chunk, …). */
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
function deliveredEndpoints(Outbox $message): array
{
    $ids = WebhookDelivery::query()->where('event_id', $message->event_id)->pluck('endpoint_id')
        ->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all();

    return $ids;
}

/** @param list<WebhookEndpoint> $endpoints */
function endpointIds(array $endpoints): array
{
    return collect($endpoints)->map(static fn (WebhookEndpoint $endpoint): string => (string) $endpoint->getKey())->sort()->values()->all();
}

it('fans a row out to every eligible endpoint and completes it with the delivery count', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    expect(app(OutboxRelay::class)->process())->toBe(1);

    $message->refresh();
    expect(deliveredEndpoints($message))->toBe(endpointIds($endpoints))
        ->and($message->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(5)
        ->and($message->fanned_out_at)->not->toBeNull()
        ->and($message->claim_token)->toBeNull()
        ->and($message->claim_until)->toBeNull()
        ->and($message->claim_generation)->toBe(1)
        ->and($message->fanout_cursor)->toBe((string) $endpoints[4]->getKey());
    Queue::assertPushed(DeliverWebhook::class, 5);
});

it('keeps a row pending when the relay dies right after the claim and resumes it after the lease', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    crashAfterCommit(1);
    expect(fn () => app(OutboxRelay::class)->process())->toThrow(RuntimeException::class, 'relay crashed');
    stopCrashing();

    $message->refresh();
    expect($message->status)->toBe('pending')
        ->and($message->claim_token)->not->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(0);

    // The lease is still live: nobody else takes the row over yet.
    expect(app(OutboxRelay::class)->process())->toBe(0);

    $this->travel(61)->seconds();
    expect(app(OutboxRelay::class)->process())->toBe(1);

    $message->refresh();
    expect($message->status)->toBe('completed')
        ->and($message->claim_generation)->toBe(2)
        ->and($message->delivery_count)->toBe(5)
        ->and(deliveredEndpoints($message))->toBe(endpointIds($endpoints));
    Queue::assertPushed(DeliverWebhook::class, 5);
});

it('resumes a row from its fan-out cursor after the relay dies between chunks, without duplicates', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    crashAfterCommit(2);
    expect(fn () => app(OutboxRelay::class)->process())->toThrow(RuntimeException::class, 'relay crashed');
    stopCrashing();

    $message->refresh();
    expect($message->status)->toBe('pending')
        ->and($message->fanout_cursor)->toBe((string) $endpoints[1]->getKey())
        ->and($message->delivery_count)->toBe(2)
        ->and(deliveredEndpoints($message))->toBe(endpointIds(array_slice($endpoints, 0, 2)));
    Queue::assertPushed(DeliverWebhook::class, 2);

    $this->travel(61)->seconds();
    expect(app(OutboxRelay::class)->process())->toBe(1);

    $message->refresh();
    expect($message->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(5)
        ->and(WebhookDelivery::query()->count())->toBe(5)
        ->and(deliveredEndpoints($message))->toBe(endpointIds($endpoints));
    Queue::assertPushed(DeliverWebhook::class, 5);
});

it('discards the chunk of a relay whose lease was taken over', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    // Worker A writes its first chunk, then stalls past its lease.
    crashAfterCommit(2);
    expect(fn () => app(OutboxRelay::class)->process())->toThrow(RuntimeException::class);
    stopCrashing();
    $tokenA = $message->refresh()->claim_token;

    // Worker B takes the row over and completes it.
    $this->travel(61)->seconds();
    expect(app(OutboxRelay::class)->process())->toBe(1);
    expect($message->refresh()->status)->toBe('completed');

    // A wakes up and runs its next chunk with the stale token: nothing is written.
    $relay = app(OutboxRelay::class);
    $result = (fn (int|string $id, string $token): ?bool => $this->materializeChunk(id: $id, token: $token))
        ->call($relay, $message->getKey(), $tokenA);

    expect($result)->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(5)
        ->and($message->refresh()->delivery_count)->toBe(5)
        ->and(deliveredEndpoints($message))->toBe(endpointIds($endpoints));
});

it('does not let a stale token write into a row another worker still fans out', function (): void {
    fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    crashAfterCommit(1);
    expect(fn () => app(OutboxRelay::class)->process())->toThrow(RuntimeException::class);
    stopCrashing();

    $relay = app(OutboxRelay::class);
    $result = (fn (int|string $id, string $token): ?bool => $this->materializeChunk(id: $id, token: $token))
        ->call($relay, $message->getKey(), str_repeat('x', 32));

    expect($result)->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(0)
        ->and($message->refresh()->fanout_cursor)->toBeNull();
});

it('completes a row nobody subscribed to with a zero delivery count', function (): void {
    WebhookTestHelpers::endpoint(['events' => ['share.created']]);
    $message = WebhookTestHelpers::outbox();

    expect(app(OutboxRelay::class)->process())->toBe(1);

    $message->refresh();
    expect($message->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(0)
        ->and($message->fanned_out_at)->not->toBeNull()
        ->and($message->fanout_cursor)->toBeNull();
    Queue::assertNothingPushed();
});

it('completes a row whose eligible endpoints fill the last chunk exactly', function (): void {
    fanoutEndpoints(4);
    $message = WebhookTestHelpers::outbox();

    app(OutboxRelay::class)->process();

    expect($message->refresh()->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(4);
});

it('does not deliver an event to an endpoint registered after it happened', function (): void {
    $before = WebhookTestHelpers::endpoint();
    $message = WebhookTestHelpers::outbox();
    $this->travel(5)->seconds();
    WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/late']);

    app(OutboxRelay::class)->process();

    expect(deliveredEndpoints($message->refresh()))->toBe([(string) $before->getKey()])
        ->and($message->delivery_count)->toBe(1);
});

it('leaves out an endpoint deactivated while the row is being fanned out', function (): void {
    $endpoints = fanoutEndpoints();
    $message = WebhookTestHelpers::outbox();

    crashAfterCommit(2);
    expect(fn () => app(OutboxRelay::class)->process())->toThrow(RuntimeException::class);
    stopCrashing();

    $endpoints[3]->forceFill(['is_active' => false])->save();
    $this->travel(61)->seconds();
    app(OutboxRelay::class)->process();

    $message->refresh();
    expect($message->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(4)
        ->and(deliveredEndpoints($message))->toBe(endpointIds([$endpoints[0], $endpoints[1], $endpoints[2], $endpoints[4]]));
});

it('claims rows in available order and skips rows under a live lease', function (): void {
    WebhookTestHelpers::endpoint();
    $later = WebhookTestHelpers::outbox(['available_at' => now()->subSecond()]);
    $earlier = WebhookTestHelpers::outbox(['available_at' => now()->subSeconds(5)]);
    $leased = WebhookTestHelpers::outbox(['available_at' => now()->subSeconds(9), 'claim_token' => str_repeat('a', 32), 'claim_until' => now()->addSeconds(30)]);

    expect(app(OutboxRelay::class)->process(limit: 1))->toBe(1)
        ->and($earlier->refresh()->status)->toBe('completed')
        ->and($later->refresh()->status)->toBe('pending')
        ->and($leased->refresh()->status)->toBe('pending')
        ->and($leased->claim_generation)->toBe(0);
});

it('rejects a fan-out chunk outside 1..1000', function (mixed $chunk): void {
    config()->set('vaulter.webhooks.relay.fanout_chunk', $chunk);
    WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox();

    expect(fn () => app(OutboxRelay::class)->process())->toThrow(InvalidConfigurationException::class);
})->with([0, 1001, 'many', 2.5]);
