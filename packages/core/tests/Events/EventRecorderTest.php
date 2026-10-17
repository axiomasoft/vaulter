<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Testing\Support\CanonicalJson;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The event pipeline (C05, D29): a change, its activity row and its outbox row commit or
 * roll back together, and host listeners see the event once, after the commit. No
 * wrapping test transaction, so a commit here is a real one.
 */
uses(TestCase::class, DatabaseMigrations::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    $this->drive = DriveTestHelpers::drive();
    $this->user = DriveTestHelpers::actor($this->drive, 7);
    $this->folder = $this->drive->folders()->create('alpha');
    $this->seen = new ArrayObject;
    Event::listen(NodeRenamed::class, function (NodeRenamed $event): void {
        $this->seen[] = [$event->eventId, app(VaulterDatabase::class)->connection()->transactionLevel()];
    });
});

function eventRecorderRename(object $test, string $name): mixed
{
    return $test->drive->actingAs($test->user)->nodes()->rename($test->folder, $name);
}

function eventRecorderEvent(object $test, ?string $eventId = null): NodeRenamed
{
    return new NodeRenamed(
        node: DriveTestHelpers::model($test->folder->id),
        fromName: 'alpha',
        toName: 'alpha',
        actor: Actor::system('replay')->ref(),
        correlationId: (string) Str::ulid(),
        eventId: $eventId,
    );
}

it('writes one activity row and one outbox row under one event id and notifies listeners after the commit', function (): void {
    eventRecorderRename($this, 'beta');

    $activity = Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->sole();
    $outbox = OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->sole();

    expect($activity->event_id)->toBe($outbox->event_id)
        ->and($outbox->payload['id'])->toBe($outbox->event_id)
        ->and($activity->context['to'])->toBe('beta')
        ->and(iterator_to_array($this->seen))->toBe([[$outbox->event_id, 0]]);
});

it('stores the thin envelope as the outbox payload', function (): void {
    eventRecorderRename($this, 'beta');

    $outbox = OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->sole();
    $payload = $outbox->payload;
    $forbidden = static function (array $value) use (&$forbidden): array {
        $found = array_values(array_intersect(array_keys($value), OutboxEnvelope::FORBIDDEN_KEYS));
        foreach ($value as $child) {
            if (is_array($child)) {
                $found = [...$found, ...$forbidden($child)];
            }
        }

        return $found;
    };

    // The key set is the contract; MySQL's JSON column returns its keys sorted, so the order is not asserted.
    expect(array_keys($payload))->toEqualCanonicalizing(['id', 'type', 'version', 'source', 'time', 'tenant_id', 'drive_id', 'subject', 'sequence', 'actor', 'correlation_id', 'changes', 'data'])
        ->and($payload['type'])->toBe('node.renamed')
        ->and(CanonicalJson::of($payload['subject']))->toBe(CanonicalJson::of(['type' => 'node', 'id' => (string) $this->folder->id]))
        ->and($payload['sequence'])->toBe(DriveTestHelpers::model($this->folder->id)->sequence)
        ->and(CanonicalJson::of($payload['actor']))->toBe(CanonicalJson::of(['type' => $this->user->getMorphClass(), 'id' => '7']))
        // The folder's creation took seq 1 of the drive's change journal, the rename seq 2.
        ->and($payload['changes'])->toBe(['seq' => 2])
        ->and($payload['time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/')
        ->and($outbox->aggregate_type)->toBe('node')
        ->and($outbox->aggregate_id)->toBe((string) $this->folder->id)
        ->and($forbidden($payload))->toBe([]);
});

it('rolls the change back when the outbox write fails', function (): void {
    WebhookTestHelpers::failOutboxInsert();

    expect(fn () => eventRecorderRename($this, 'beta'))->toThrow(RuntimeException::class, 'outbox insert failed')
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('alpha')
        ->and(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->exists())->toBeFalse()
        ->and(count($this->seen))->toBe(0);
});

it('refuses to record outside a transaction', function (): void {
    expect(fn () => app(EventRecorder::class)->record(eventRecorderEvent($this)))
        ->toThrow(LogicException::class, 'must run inside a VaulterDatabase transaction');
});

it('refuses to record the same event instance twice', function (): void {
    $event = eventRecorderEvent($this);
    $database = app(VaulterDatabase::class);
    $database->transaction(fn () => app(EventRecorder::class)->record($event));

    expect(fn () => $database->transaction(fn () => app(EventRecorder::class)->record($event)))
        ->toThrow(LogicException::class, 'already recorded');
});

it('ignores a replay of a stored occurrence and does not notify listeners again', function (): void {
    $eventId = (string) Str::ulid();
    $database = app(VaulterDatabase::class);

    $database->transaction(fn () => app(EventRecorder::class)->record(eventRecorderEvent($this, $eventId)));
    $database->transaction(fn () => app(EventRecorder::class)->record(eventRecorderEvent($this, $eventId)));

    expect(Activity::query()->where('event_id', $eventId)->count())->toBe(1)
        ->and(OutboxEvent::query()->where('event_id', $eventId)->count())->toBe(1)
        ->and(iterator_to_array($this->seen))->toBe([[$eventId, 0]]);
});

it('rolls back an occurrence that only part of the sinks would store', function (): void {
    $eventId = (string) Str::ulid();
    WebhookTestHelpers::outbox(['event_id' => $eventId]);

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record(eventRecorderEvent($this, $eventId))))
        ->toThrow(LogicException::class, 'stored only partially')
        ->and(Activity::query()->where('event_id', $eventId)->exists())->toBeFalse()
        ->and(count($this->seen))->toBe(0);
});

it('does not notify listeners of an attempt whose commit failed and was retried', function (): void {
    $failed = false;
    Event::listen(TransactionCommitting::class, function () use (&$failed): void {
        if (!$failed) {
            $failed = true;

            throw new PDOException('database is locked');
        }
    });

    eventRecorderRename($this, 'beta');

    $outbox = OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->sole();
    expect($failed)->toBeTrue()
        ->and(array_column(iterator_to_array($this->seen), 0))->toBe([$outbox->event_id]);
});

it('writes the activity row only while its feature is on, and the outbox whatever modules are active', function (bool $activity, bool $webhooks): void {
    config()->set('vaulter-drive.features.activity', $activity);
    WebhookTestHelpers::switchModule($this->app, $webhooks);

    eventRecorderRename($this, 'beta');

    $outbox = OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->sole();
    expect(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->count())->toBe($activity ? 1 : 0)
        ->and(WebhookTestHelpers::outboxDelivery($outbox->event_id) !== null)->toBe($webhooks)
        ->and(count($this->seen))->toBe(1);
})->with([
    'both' => [true, true],
    'activity only' => [true, false],
    'webhooks only' => [false, true],
    'neither' => [false, false],
]);

it('reports a failing listener without undoing the committed change', function (): void {
    Exceptions::fake();
    $metrics = new FakeMetricsRecorder;
    $this->app->instance(MetricsRecorder::class, $metrics);
    $this->app->forgetInstance(EventRecorder::class);
    Event::listen(NodeRenamed::class, static function (): void {
        throw new RuntimeException('listener down');
    });

    $renamed = eventRecorderRename($this, 'beta');

    expect($renamed->name)->toBe('beta')
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('beta')
        ->and(OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->count())->toBe(1);
    Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'listener down');
    $metrics->assertCounter(name: 'vaulter_event_listener_failures_total', labels: ['type' => 'node.renamed']);
});
