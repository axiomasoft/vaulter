<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Doctor\Data\DoctorContext;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\Checks\OutboxConsumerGapCheck;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Integration\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Integration\Tests\Outbox\Fixtures\EphemeralConsumer;
use Vaulter\Integration\Tests\Outbox\Fixtures\MisnamedConsumer;
use Vaulter\Integration\Tests\Outbox\Fixtures\ScriptedConsumer;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The outbox relay: registration, reconciliation up to the settle window, fenced delivery with full
 * jitter, attempt budget and DLQ, hold and detach with an explicit gap, retention that loses nothing undelivered,
 * and replay. The consumers are fixtures contributed to a resealed module graph.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    ScriptedConsumer::reset();
    $this->metrics = new FakeMetricsRecorder;
    $this->app->instance(MetricsRecorder::class, $this->metrics);
    config()->set('vaulter.outbox.settle_window_seconds', 300);
    config()->set('vaulter.outbox.max_consumer_hold_seconds', 3600);
    config()->set('vaulter.outbox.retention_seconds', 600);
    config()->set('vaulter.outbox.dead_retention_seconds', 6000);
});

/**
 * Reseals the kernel with `$consumers` (contribution id => class) as the only outbox consumers.
 *
 * @param array<string, class-string<OutboxConsumer>> $consumers
 */
function relayConsumers(array $consumers): void
{
    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $contributions = [];
    foreach ($consumers as $id => $class) {
        $contributions[] = new SlotContribution(OutboxConsumer::class, $id, 'core', $class, 0, null);
    }
    $arguments['contributions'][OutboxConsumer::class] = $contributions;
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...$arguments));
    app()->instance(Kernel::class, $kernel);
}

function relayPass(): array
{
    return app(OutboxRelay::class)->pass();
}

/** An event written `$age` seconds ago without delivery rows, like a process whose module graph had no consumer. */
function bareEvent(int $age = 900, string $type = 'folder.created'): OutboxEvent
{
    return WebhookTestHelpers::outbox(['type' => $type, 'created_at' => now()->subSeconds($age)], consumer: null);
}

function eventFor(string $consumer, int $age = 900): OutboxEvent
{
    return WebhookTestHelpers::outbox(['created_at' => now()->subSeconds($age)], consumer: $consumer);
}

/** @return array<string, mixed>|null */
function consumerRow(string $id): ?array
{
    return app(OutboxStore::class)->consumer($id);
}

function rowOf(OutboxEvent $event, string $consumer = ScriptedConsumer::ID): ?array
{
    return WebhookTestHelpers::outboxDelivery($event->event_id, $consumer);
}

function setReconciled(string $id, int $seq): void
{
    app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table(OutboxStore::CONSUMERS))
        ->where('consumer_id', $id)->update(['reconciled_through_seq' => $seq, 'activation_seq' => $seq]);
}

it('registers a new consumer active at the current seq', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $old = bareEvent();

    relayPass();

    expect(consumerRow(ScriptedConsumer::ID))->toMatchArray([
        'state' => 'active',
        'delivery_class' => 'durable',
        'activation_seq' => $old->outbox_seq,
        'reconciled_through_seq' => $old->outbox_seq,
    ])->and(rowOf($old))->toBeNull();
});

it('refuses a consumer whose id differs from its contribution', function (): void {
    relayConsumers(['test.misnamed' => MisnamedConsumer::class]);

    expect(fn () => relayPass())->toThrow(LogicException::class, 'must implement');
});

it('reconciles the rows a process with an older graph missed, only up to the settle window', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    setReconciled(ScriptedConsumer::ID, 0);

    $settled = bareEvent(age: 900);
    ScriptedConsumer::$ignored = ['share.created'];
    $unsubscribed = bareEvent(age: 800, type: 'share.created');
    $fresh = bareEvent(age: 10);

    relayPass();

    expect(rowOf($settled)['state'] ?? null)->toBe('done')
        ->and(rowOf($unsubscribed))->toBeNull()
        ->and(rowOf($fresh))->toBeNull()
        ->and(consumerRow(ScriptedConsumer::ID)['reconciled_through_seq'])->toBe($unsubscribed->outbox_seq);

    // Once the fresh event settles, the next pass materializes and delivers it.
    $this->travel(301)->seconds();
    relayPass();
    expect(rowOf($fresh)['state'] ?? null)->toBe('done')
        ->and(consumerRow(ScriptedConsumer::ID)['reconciled_through_seq'])->toBe($fresh->outbox_seq);
});

it('does not advance past an event committed late inside the settle window', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    setReconciled(ScriptedConsumer::ID, 0);

    $early = bareEvent(age: 900);
    // A long transaction wrote this seq 100 seconds ago and committed only now.
    $late = bareEvent(age: 100);

    relayPass();

    expect(consumerRow(ScriptedConsumer::ID)['reconciled_through_seq'])->toBe($early->outbox_seq)
        ->and(rowOf($late))->toBeNull();
    $this->travel(201)->seconds();
    relayPass();
    expect(rowOf($late)['state'] ?? null)->toBe('done');
});

it('retries with full jitter inside the budget, then dead-letters', function (): void {
    config()->set('vaulter.outbox.max_attempts', 2);
    config()->set('vaulter.outbox.backoff_base_seconds', 10);
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $event = eventFor(ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::retry('receiver down'), ConsumeResult::retry('receiver down')];

    relayPass();
    $row = rowOf($event);
    $delay = CarbonImmutable::parse($row['next_attempt_at'])->getTimestamp() - now()->getTimestamp();
    expect($row['state'])->toBe('pending')
        ->and((int) $row['attempts'])->toBe(1)
        ->and($row['last_error'])->toBe('receiver down')
        ->and($delay)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(20);

    $this->travel(21)->seconds();
    relayPass();
    expect(rowOf($event)['state'])->toBe('dead')
        ->and((int) rowOf($event)['attempts'])->toBe(2);
    $this->metrics->assertCounter(name: 'vaulter_outbox_dead_total', labels: ['consumer' => ScriptedConsumer::ID]);
});

it('dead-letters a dead result at once and retries a consumer that throws', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $dead = eventFor(ScriptedConsumer::ID, age: 950);
    $throws = eventFor(ScriptedConsumer::ID, age: 900);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison'), 'throw'];

    relayPass();

    expect(rowOf($dead)['state'])->toBe('dead')
        ->and(rowOf($dead)['last_error'])->toBe('poison')
        ->and(rowOf($throws)['state'])->toBe('pending')
        ->and(rowOf($throws)['last_error'])->toContain('consumer failed');
});

it('pauses a consumer the graph no longer holds and resumes it where it stopped', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    relayConsumers([]);
    $waiting = eventFor(ScriptedConsumer::ID);

    relayPass();
    expect(consumerRow(ScriptedConsumer::ID)['state'])->toBe('paused')
        ->and(consumerRow(ScriptedConsumer::ID)['paused_at'])->not->toBeNull()
        ->and(rowOf($waiting)['state'])->toBe('pending');

    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    expect(consumerRow(ScriptedConsumer::ID)['state'])->toBe('active')
        ->and(rowOf($waiting)['state'])->toBe('done');
});

it('detaches a durable consumer paused past its hold with an explicit gap, metric and doctor error', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    relayConsumers([]);
    $open = eventFor(ScriptedConsumer::ID);
    relayPass();
    $unreconciled = bareEvent(age: 10);

    $this->travel(3601)->seconds();
    relayPass();

    $consumer = consumerRow(ScriptedConsumer::ID);
    expect($consumer['state'])->toBe('detached')
        ->and(rowOf($open)['state'])->toBe('dead')
        ->and(rowOf($open)['last_error'])->toBe('consumer detached')
        ->and($consumer['gaps'])->toHaveCount(1)
        ->and($consumer['gaps'][0])->toMatchArray(['from_seq' => $open->outbox_seq, 'to_seq' => $unreconciled->outbox_seq, 'events' => 2, 'acknowledged_at' => null]);
    $this->metrics->assertCounter(name: 'vaulter_outbox_consumer_gap_events_total', labels: ['consumer' => ScriptedConsumer::ID]);
    $findings = app(OutboxConsumerGapCheck::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 100, now: now()->toDateTimeImmutable()));
    expect(array_map(static fn ($f): string => $f->code, $findings))->toContain('core.outbox.consumer_gap');

    // Re-enabled after the detach, it starts over at the current seq; the gap stays until it is closed.
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    expect(consumerRow(ScriptedConsumer::ID))->toMatchArray(['state' => 'active', 'activation_seq' => $unreconciled->outbox_seq])
        ->and(consumerRow(ScriptedConsumer::ID)['gaps'][0]['acknowledged_at'])->toBeNull();
});

it('records the events committed while a consumer is detached as a gap when it returns', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    relayConsumers([]);
    relayPass();
    bareEvent(age: 10);
    $this->travel(3601)->seconds();
    relayPass();
    expect(consumerRow(ScriptedConsumer::ID)['state'])->toBe('detached');

    $first = bareEvent(age: 10);
    $last = bareEvent(age: 10);
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();

    $consumer = consumerRow(ScriptedConsumer::ID);
    expect($consumer['state'])->toBe('active')
        ->and($consumer['reconciled_through_seq'])->toBe($last->outbox_seq)
        ->and($consumer['gaps'])->toHaveCount(2)
        ->and($consumer['gaps'][1])->toMatchArray(['from_seq' => $first->outbox_seq, 'to_seq' => $last->outbox_seq, 'events' => 2, 'acknowledged_at' => null]);
});

it('detaches an ephemeral consumer without a gap and lets retention pass it', function (): void {
    relayConsumers([EphemeralConsumer::ID => EphemeralConsumer::class]);
    relayPass();
    relayConsumers([]);
    $open = eventFor(EphemeralConsumer::ID);
    relayPass();

    expect(app(OutboxRelay::class)->prune()['events'])->toBe(0);
    $this->travel(3601)->seconds();
    relayPass();

    expect(consumerRow(EphemeralConsumer::ID))->toMatchArray(['state' => 'detached', 'gaps' => []])
        ->and(rowOf($open, EphemeralConsumer::ID)['state'])->toBe('dead');
    expect(app(OutboxRelay::class)->prune()['events'])->toBe(1);
});

it('keeps an event with an open delivery or below a paused durable consumer, and deletes it once both are past', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    $delivered = eventFor(ScriptedConsumer::ID, age: 900);
    ScriptedConsumer::$answers = [ConsumeResult::done(), ConsumeResult::retry('later')];
    $open = eventFor(ScriptedConsumer::ID, age: 850);
    relayPass();

    expect(rowOf($delivered)['state'])->toBe('done')
        ->and(rowOf($open)['state'])->toBe('pending');

    // While the consumer has not reconciled past the events, retention holds both.
    setReconciled(ScriptedConsumer::ID, 0);
    expect(app(OutboxRelay::class)->prune())->toMatchArray(['events' => 0]);

    setReconciled(ScriptedConsumer::ID, $open->outbox_seq);
    $pruned = app(OutboxRelay::class)->prune();
    expect($pruned)->toMatchArray(['events' => 1, 'done' => 1])
        ->and(OutboxEvent::query()->where('event_id', $delivered->event_id)->exists())->toBeFalse()
        ->and(rowOf($delivered))->toBeNull()
        ->and(OutboxEvent::query()->where('event_id', $open->event_id)->exists())->toBeTrue();
});

it('prunes dead rows after their own retention', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $event = eventFor(ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison')];
    relayPass();

    expect(app(OutboxRelay::class)->prune()['dead'])->toBe(0);
    $this->travel(6001)->seconds();
    expect(app(OutboxRelay::class)->prune()['dead'])->toBe(1)
        ->and(rowOf($event))->toBeNull();
});

it('replays a range to a consumer, acknowledges the gaps it covers and reports what retention lost', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $event = eventFor(ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison')];
    relayPass();
    expect(rowOf($event)['state'])->toBe('dead');
    $missing = bareEvent();

    $this->artisan('vaulter:relay:replay', ['--consumer' => ScriptedConsumer::ID, '--from' => $event->outbox_seq, '--to' => $missing->outbox_seq])
        ->assertSuccessful();
    expect(rowOf($event))->toMatchArray(['state' => 'pending', 'attempts' => 0])
        ->and(rowOf($missing)['state'] ?? null)->toBe('pending');
    relayPass();
    expect(rowOf($event)['state'])->toBe('done');

    // Seqs below the first live event are gone: the replay says so and exits 1.
    $this->artisan('vaulter:relay:replay', ['--consumer' => ScriptedConsumer::ID, '--from' => 0, '--to' => $missing->outbox_seq])
        ->assertExitCode(1);
    $this->artisan('vaulter:relay:replay', ['--consumer' => 'test.unknown', '--from' => 1, '--to' => 2])
        ->assertExitCode(1);
});

it('publishes the dead rows of each consumer as a gauge and reads zero once they are replayed', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class, EphemeralConsumer::ID => EphemeralConsumer::class]);
    $event = eventFor(ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison')];
    relayPass();

    $metrics = $this->metrics;
    $gauge = static fn (string $consumer): array => array_values(array_map(
        static fn (array $e): int|float => $e['value'],
        array_filter($metrics->emissions(), static fn (array $e): bool => $e['type'] === 'gauge' && $e['name'] === 'vaulter_outbox_dead_rows' && $e['labels'] === ['consumer' => $consumer]),
    ));
    expect($gauge(ScriptedConsumer::ID))->toBe([1])
        ->and($gauge(EphemeralConsumer::ID))->toBe([0]);

    $this->artisan('vaulter:relay:replay', ['--consumer' => ScriptedConsumer::ID, '--from' => $event->outbox_seq, '--to' => $event->outbox_seq])
        ->expectsOutputToContain('(1 dead returned to pending)')
        ->assertSuccessful();
    relayPass();

    expect($gauge(ScriptedConsumer::ID))->toBe([1, 0]);
});

it('returns only the dead rows of the range, with a fresh budget, and counts them', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    $dead = eventFor(ScriptedConsumer::ID);
    $done = eventFor(ScriptedConsumer::ID);
    $outside = eventFor(ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison'), ConsumeResult::done(), ConsumeResult::dead('other')];
    relayPass();

    $result = app(OutboxRelay::class)->replay(ScriptedConsumer::ID, $dead->outbox_seq, $done->outbox_seq, ackOnly: false);

    expect($result['dead'])->toBe(1)
        ->and($result['rows'])->toBe(2)
        ->and(rowOf($dead))->toMatchArray(['state' => 'pending', 'attempts' => 0, 'last_error' => null])
        ->and(rowOf($done)['state'])->toBe('pending')
        ->and(rowOf($outside)['state'])->toBe('dead');
});

it('does not let a dead delivery of one consumer delay another consumer', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class, EphemeralConsumer::ID => EphemeralConsumer::class]);
    $poison = eventFor(ScriptedConsumer::ID, age: 950);
    $next = eventFor(ScriptedConsumer::ID, age: 940);
    $other = eventFor(EphemeralConsumer::ID, age: 930);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison')];

    $finished = relayPass();

    expect(rowOf($poison)['state'])->toBe('dead')
        ->and(rowOf($next)['state'])->toBe('done')
        ->and(rowOf($other, EphemeralConsumer::ID)['state'])->toBe('done')
        ->and($finished[EphemeralConsumer::ID])->toBe(1);
});

it('acknowledges a gap without replay with --ack-only', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class]);
    relayPass();
    relayConsumers([]);
    eventFor(ScriptedConsumer::ID);
    relayPass();
    $this->travel(3601)->seconds();
    relayPass();
    $gap = consumerRow(ScriptedConsumer::ID)['gaps'][0];

    $this->artisan('vaulter:relay:replay', ['--consumer' => ScriptedConsumer::ID, '--from' => $gap['from_seq'], '--to' => $gap['to_seq'], '--ack-only' => true])
        ->assertSuccessful();

    expect(consumerRow(ScriptedConsumer::ID)['gaps'][0]['acknowledged_at'])->not->toBeNull();
    $findings = app(OutboxConsumerGapCheck::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 100, now: now()->toDateTimeImmutable()));
    expect(array_map(static fn ($f): string => $f->code, $findings))->not->toContain('core.outbox.consumer_gap');
});

it('runs a single pass for one consumer with vaulter:relay', function (): void {
    relayConsumers([ScriptedConsumer::ID => ScriptedConsumer::class, EphemeralConsumer::ID => EphemeralConsumer::class]);
    $durable = eventFor(ScriptedConsumer::ID);
    $ephemeral = eventFor(EphemeralConsumer::ID);

    $this->artisan('vaulter:relay', ['--consumer' => EphemeralConsumer::ID, '--once' => true])->assertSuccessful();

    expect(rowOf($ephemeral, EphemeralConsumer::ID)['state'])->toBe('done')
        ->and(rowOf($durable)['state'])->toBe('pending');
    $this->artisan('vaulter:relay', ['--consumer' => 'test.unknown'])->assertExitCode(1);
});
