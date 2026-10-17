<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookFanoutConsumer;

uses(EnablesWebhooksModule::class);

/**
 * Two relay processes on PostgreSQL (D29): SKIP LOCKED splits the due outbox deliveries between them,
 * every (event, endpoint) pair is materialized exactly once, and a relay killed in the middle of a
 * fan-out chunk loses nothing — its transaction rolls back and the delivery is resumed from its
 * continuation once its lease lapses.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter-webhooks.relay.fanout_chunk', 3);
    config()->set('vaulter.outbox.lease_seconds', 60);
});

/** Drains the relay in small batches until nothing is due; returns this process's backend pid. */
function drainRelay(): array
{
    Bus::fake();
    $finished = 0;
    while (($batch = array_sum(app(OutboxRelay::class)->pass(limit: 5))) > 0) {
        $finished += $batch;
    }

    return ['pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid, 'finished' => $finished];
}

/** @return list<string> the states of the webhooks.fanout delivery rows */
function fanoutStates(): array
{
    return DB::table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))->where('consumer_id', WebhookFanoutConsumer::ID)->pluck('state')->all();
}

it('materializes every pair exactly once when two relays drain the same outbox', function (): void {
    for ($index = 0; $index < 7; $index++) {
        WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/'.$index]);
    }
    DB::table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))->delete();
    OutboxEvent::query()->delete();
    for ($index = 0; $index < 50; $index++) {
        WebhookTestHelpers::outbox(['event_id' => 'race-'.$index]);
    }

    $outcomes = ForkRace::run([fn (): array => drainRelay(), fn (): array => drainRelay()], timeoutMs: 60_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($outcomes[0]->value['pid'])->not->toBe($outcomes[1]->value['pid'])
        ->and($outcomes[0]->value['finished'] + $outcomes[1]->value['finished'])->toBe(50);

    $pairs = WebhookDelivery::query()->get(['event_id', 'endpoint_id'])
        ->map(static fn (WebhookDelivery $delivery): string => $delivery->event_id.'|'.$delivery->endpoint_id);
    expect($pairs)->toHaveCount(350)
        ->and($pairs->unique())->toHaveCount(350)
        ->and(array_count_values(fanoutStates()))->toBe(['done' => 50]);
});

it('resumes a delivery after its relay is killed in the middle of a chunk', function (): void {
    for ($index = 0; $index < 7; $index++) {
        WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/'.$index]);
    }
    DB::table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))->delete();
    OutboxEvent::query()->delete();
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
        app(OutboxRelay::class)->pass();

        throw new LogicException('the relay was not killed');
    }]);

    expect($outcomes[0]->exception)->toBe('ForkRace\\MissingResult');

    $row = WebhookTestHelpers::outboxDelivery($message->event_id);
    expect($row['state'])->toBe('leased')
        ->and(WebhookDelivery::query()->where('event_id', 'killed')->count())->toBe(3);

    Bus::fake();
    expect(app(OutboxRelay::class)->pass())->toBe([WebhookFanoutConsumer::ID => 0]);
    $this->travel(61)->seconds();
    expect(app(OutboxRelay::class)->pass())->toBe([WebhookFanoutConsumer::ID => 1]);

    $endpointIds = WebhookDelivery::query()->where('event_id', 'killed')->pluck('endpoint_id');
    expect(WebhookTestHelpers::outboxDelivery($message->event_id))->toMatchArray(['state' => 'done', 'lease_generation' => 2])
        ->and($endpointIds)->toHaveCount(7)
        ->and($endpointIds->unique())->toHaveCount(7);
});
