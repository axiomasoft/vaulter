<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Vaulter\Attributes\EventSpec;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Concerns\DescribesEvent;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Events\Outbox\OutboxWriter;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;
use Vaulter\Providers\EventsRegistrar;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

it('binds one event recorder for the application', function (): void {
    (new EventsRegistrar)->register($this->app);

    expect($this->app->make(EventRecorder::class))->toBe($this->app->make(EventRecorder::class));
});

it('rejects a tenant id the host key column cannot store before the insert', function (string $hostKeys, string $tenant): void {
    $previous = config('vaulter.ids.host_keys');
    config()->set('vaulter.ids.host_keys', $hostKeys);

    try {
        $event = new HostKeyColumnProbeEvent($tenant);

        expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($event): void {
            app(OutboxWriter::class)->append($event, new SinkWriter(app(VaulterDatabase::class)));
        }))->toThrow(InvalidArgumentException::class, 'ids.host_keys ['.$hostKeys.']');
        expect(OutboxEvent::query()->where('event_id', $event->eventId)->exists())->toBeFalse();
    } finally {
        config()->set('vaulter.ids.host_keys', $previous);
    }
})->with([
    'varchar(64) and a 191-byte token' => ['string', str_repeat('a', 191)],
    'varchar(64) and 65 bytes' => ['string', str_repeat('b', 65)],
    'uuid column and a ulid' => ['uuid', (string) Str::ulid()],
    'bigint column and a ulid' => ['bigint', (string) Str::ulid()],
    'bigint column past the signed maximum' => ['bigint', '9223372036854775808'],
    'char(26) and a uuid' => ['ulid', (string) Str::uuid()],
]);

it('stores a tenant id the host key column can hold, including null', function (string $hostKeys, ?string $tenant): void {
    $previous = config('vaulter.ids.host_keys');
    config()->set('vaulter.ids.host_keys', $hostKeys);

    try {
        $event = new HostKeyColumnProbeEvent($tenant);
        $result = app(VaulterDatabase::class)->transaction(
            fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter(app(VaulterDatabase::class))),
        );
        $stored = OutboxEvent::query()->where('event_id', $event->eventId)->sole()->tenant_id;

        expect($result)->toBe(SinkResult::Stored)
            ->and($stored === null ? null : (string) $stored)->toBe($tenant);
    } finally {
        config()->set('vaulter.ids.host_keys', $previous);
    }
})->with([
    'null' => ['string', null],
    '64 bytes' => ['string', str_repeat('c', 64)],
    'uuid' => ['uuid', '018f4c3a-7c3e-7b2a-8f1d-6a5b4c3d2e1f'],
    'ulid' => ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'short token in char(26)' => ['ulid', 'tenant-1'],
    'bigint' => ['bigint', '42'],
    'signed bigint maximum' => ['bigint', '9223372036854775807'],
]);

it('does not acknowledge a replay gap while a leased delivery remains', function (): void {
    $done = eventsRegistrarOutboxRow();
    $leased = eventsRegistrarOutboxRow();
    eventsRegistrarConsumer($done['outbox_seq'], $leased['outbox_seq']);
    eventsRegistrarDelivery($done, state: 'done', generation: 2, attempts: 3);
    eventsRegistrarDelivery($leased, state: 'leased', generation: 4, attempts: 1, owner: 'worker-a');

    $result = app(OutboxStore::class)->replay('events.registrar.probe', ['core.probe.replay'], $done['outbox_seq'], $leased['outbox_seq'], CarbonImmutable::now());

    $doneRow = eventsRegistrarDeliveryRow($done['event_id']);
    $leasedRow = eventsRegistrarDeliveryRow($leased['event_id']);
    expect($result['rows'])->toBe(1)
        ->and($result['dead'])->toBe(0)
        ->and($doneRow->state)->toBe('pending')
        ->and((int) $doneRow->attempts)->toBe(0)
        ->and($leasedRow->state)->toBe('leased')
        ->and((int) $leasedRow->lease_generation)->toBe(4)
        ->and($leasedRow->lease_owner)->toBe('worker-a')
        ->and((int) $leasedRow->attempts)->toBe(1)
        ->and(app(OutboxStore::class)->consumer('events.registrar.probe')['gaps'][0]['acknowledged_at'] ?? null)->toBeNull();
});

it('acknowledges a replay gap once no delivery in the range is leased', function (): void {
    $done = eventsRegistrarOutboxRow();
    eventsRegistrarConsumer($done['outbox_seq'], $done['outbox_seq']);
    eventsRegistrarDelivery($done, state: 'done', generation: 2, attempts: 3);

    $result = app(OutboxStore::class)->replay('events.registrar.probe', ['core.probe.replay'], $done['outbox_seq'], $done['outbox_seq'], CarbonImmutable::now());

    expect($result['rows'])->toBe(1)
        ->and(eventsRegistrarDeliveryRow($done['event_id'])->state)->toBe('pending')
        ->and(app(OutboxStore::class)->consumer('events.registrar.probe')['gaps'][0]['acknowledged_at'] ?? null)->not->toBeNull();
});

/** @return array{event_id: string, outbox_seq: int} */
function eventsRegistrarOutboxRow(): array
{
    $eventId = (string) Str::ulid();
    $table = app(DatabaseConfig::class)->table(OutboxStore::EVENTS);
    app(VaulterDatabase::class)->connection()->table($table)->insert([
        'event_id' => $eventId,
        'tenant_id' => null,
        'scope_kind' => 'tenant',
        'scope_id' => null,
        'aggregate_type' => 'node',
        'aggregate_id' => '1',
        'sequence' => null,
        'type' => 'core.probe.replay',
        'external' => false,
        'payload' => '{}',
        'created_at' => now(),
    ]);
    $seq = app(VaulterDatabase::class)->connection()->table($table)->where('event_id', $eventId)->value('outbox_seq');

    return ['event_id' => $eventId, 'outbox_seq' => (int) $seq];
}

function eventsRegistrarConsumer(int $from, int $to): void
{
    $now = now();
    app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::CONSUMERS))->insert([
        'consumer_id' => 'events.registrar.probe',
        'delivery_class' => 'durable',
        'state' => 'active',
        'activation_seq' => $to,
        'reconciled_through_seq' => $to,
        'lease_generation' => 0,
        'gaps' => json_encode([[
            'from_seq' => $from,
            'to_seq' => $to,
            'events' => 1,
            'at' => $now->toIso8601String(),
            'acknowledged_at' => null,
        ]], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/** @param array{event_id: string, outbox_seq: int} $event */
function eventsRegistrarDelivery(array $event, string $state, int $generation, int $attempts, ?string $owner = null): void
{
    $now = now();
    app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))->insert([
        'event_id' => $event['event_id'],
        'consumer_id' => 'events.registrar.probe',
        'outbox_seq' => $event['outbox_seq'],
        'state' => $state,
        'attempts' => $attempts,
        'next_attempt_at' => $now,
        'lease_owner' => $owner,
        'lease_generation' => $generation,
        'lease_expires_at' => $owner === null ? null : $now->copy()->addMinute(),
        'created_at' => $now,
        'updated_at' => $now,
        'done_at' => $state === 'done' ? $now : null,
    ]);
}

function eventsRegistrarDeliveryRow(string $eventId): object
{
    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))
        ->where('event_id', $eventId)
        ->where('consumer_id', 'events.registrar.probe')
        ->first();

    expect($row)->toBeObject();

    return $row;
}

enum HostKeyColumnProbeType: string implements EventTypeContract
{
    use DescribesEvent;

    #[EventSpec(subject: 'node', data: [], activity: false, external: false, journal: null, description: 'Probe for the outbox tenant column.')]
    case Probe = 'core.probe.tenant';
}

final readonly class HostKeyColumnProbeEvent extends DomainEvent
{
    public function __construct(private ?string $tenant)
    {
        parent::__construct(actor: new ActorRef(type: 'user', id: '1'), correlationId: 'corr-host-key');
    }

    public function type(): HostKeyColumnProbeType
    {
        return HostKeyColumnProbeType::Probe;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->tenant, driveId: null, profile: 'default');
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'node', id: '1');
    }

    public function data(): array
    {
        return [];
    }
}
