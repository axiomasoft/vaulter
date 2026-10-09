<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;

/**
 * Two relay processes on PostgreSQL: SKIP LOCKED splits the due rows between them,
 * every (event, endpoint) pair is materialized exactly once, and a relay killed in the
 * middle of a chunk loses nothing — its transaction rolls back and the row is resumed
 * once its lease lapses.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.webhooks.relay.fanout_chunk', 3);
    config()->set('vaulter.webhooks.relay.claim_seconds', 60);
});

/** Drains the relay in small batches until nothing is due; returns this process's backend pid. */
function drainRelay(): array
{
    Bus::fake();
    $claimed = 0;
    while (($batch = app(OutboxRelay::class)->process(limit: 5)) > 0) {
        $claimed += $batch;
    }

    return ['pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid, 'claimed' => $claimed];
}

it('materializes every pair exactly once when two relays drain the same outbox', function (): void {
    $endpoints = [];
    for ($index = 0; $index < 7; $index++) {
        $endpoints[] = WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/'.$index]);
    }
    Outbox::query()->delete();
    for ($index = 0; $index < 50; $index++) {
        WebhookTestHelpers::outbox(['event_id' => 'race-'.$index]);
    }

    $outcomes = ForkRace::run([fn (): array => drainRelay(), fn (): array => drainRelay()], timeoutMs: 60_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($outcomes[0]->value['pid'])->not->toBe($outcomes[1]->value['pid'])
        ->and($outcomes[0]->value['claimed'] + $outcomes[1]->value['claimed'])->toBe(50);

    $pairs = WebhookDelivery::query()->get(['event_id', 'endpoint_id'])
        ->map(static fn (WebhookDelivery $delivery): string => $delivery->event_id.'|'.$delivery->endpoint_id);
    expect($pairs)->toHaveCount(350)
        ->and($pairs->unique())->toHaveCount(350)
        ->and(Outbox::query()->where('status', 'completed')->where('delivery_count', 7)->count())->toBe(50)
        ->and(Outbox::query()->whereNotNull('claim_token')->count())->toBe(0);
});

it('resumes a row after its relay is killed in the middle of a chunk', function (): void {
    for ($index = 0; $index < 7; $index++) {
        WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/'.$index]);
    }
    Outbox::query()->delete();
    $message = WebhookTestHelpers::outbox(['event_id' => 'killed']);
    $deliveries = (new WebhookDelivery)->getTable();

    $outcomes = ForkRace::run([function () use ($deliveries): never {
        Bus::fake();
        $inserts = 0;
        // Die on the second delivery insert of the second chunk: the first chunk is
        // committed, the second is open with one row written.
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$inserts, $deliveries): void {
            if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, $deliveries) && ++$inserts === 5) {
                posix_kill(getmypid(), SIGKILL);
            }
        });
        app(OutboxRelay::class)->process();

        throw new LogicException('the relay was not killed');
    }]);

    expect($outcomes[0]->exception)->toBe('ForkRace\\MissingResult');

    $message->refresh();
    expect($message->status)->toBe('pending')
        ->and($message->delivery_count)->toBe(3)
        ->and(WebhookDelivery::query()->where('event_id', 'killed')->count())->toBe(3);

    Bus::fake();
    expect(app(OutboxRelay::class)->process())->toBe(0);
    $this->travel(61)->seconds();
    expect(app(OutboxRelay::class)->process())->toBe(1);

    $message->refresh();
    $endpointIds = WebhookDelivery::query()->where('event_id', 'killed')->pluck('endpoint_id');
    expect($message->status)->toBe('completed')
        ->and($message->delivery_count)->toBe(7)
        ->and($message->claim_generation)->toBe(2)
        ->and($endpointIds)->toHaveCount(7)
        ->and($endpointIds->unique())->toHaveCount(7);
});
