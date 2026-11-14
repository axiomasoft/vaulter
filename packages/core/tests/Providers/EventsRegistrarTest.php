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

it('dates reconciliation after payload preparation and the insert wait within the transaction window', function (): void {
    $started = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $this->travelTo($started);
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    $table = app(DatabaseConfig::class)->table(OutboxStore::EVENTS);
    $wrappedTable = $connection->getQueryGrammar()->wrapTable($table);
    $active = true;
    $insertWaits = 0;
    $bodyCalls = 0;
    $connection->beforeExecuting(function (string $sql) use (&$active, &$insertWaits, $wrappedTable): void {
        if ($active && str_starts_with(strtolower(ltrim($sql)), 'insert') && str_contains($sql, $wrappedTable)) {
            $active = false;
            $insertWaits++;
            $this->travel(2)->seconds();
        }
    });

    try {
        $before = app(OutboxStore::class)->settledSeq($started->addSecond());
        $event = new OutboxTimestampProbeEvent(function () use (&$bodyCalls): void {
            $bodyCalls++;
            $this->travel(2)->seconds();
        });
        $result = $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
        $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();

        // Four seconds elapsed in a transaction bounded by a five-second settle window.
        expect($result)->toBe(SinkResult::Stored)
            ->and(CarbonImmutable::now()->diffInSeconds($started, absolute: true))->toBeLessThan(5)
            ->and($bodyCalls)->toBe(1)
            ->and($insertWaits)->toBe(1)
            ->and($row->created_at->toIso8601String())->toBe($started->toIso8601String())
            ->and($row->staged_at?->toIso8601String())->toBe($started->addSeconds(4)->toIso8601String())
            ->and($row->payload['time'])->toBe($started->format('Y-m-d\TH:i:s.v\Z'))
            ->and($row->payload)->not->toHaveKey('staged_at')
            ->and(app(OutboxStore::class)->settledSeq($started->addSecond()))->toBe($before)
            ->and(OutboxEvent::query()->where('event_id', $event->eventId)->count())->toBe(1);
    } finally {
        $active = false;
        $this->travelBack();
    }
});

it('rolls the inserted event back if its reconciliation timestamp cannot be stored', function (): void {
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    $wrappedTable = $connection->getQueryGrammar()->wrapTable(app(DatabaseConfig::class)->table(OutboxStore::EVENTS));
    $active = true;
    $connection->beforeExecuting(static function (string $sql) use (&$active, $wrappedTable): void {
        if ($active && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, $wrappedTable)) {
            throw new RuntimeException('reconciliation timestamp failed');
        }
    });
    $event = new OutboxTimestampProbeEvent;

    try {
        expect(fn () => $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database))))
            ->toThrow(RuntimeException::class, 'reconciliation timestamp failed');
        expect(OutboxEvent::query()->where('event_id', $event->eventId)->exists())->toBeFalse();
    } finally {
        $active = false;
    }
});

it('never treats an unstaged event as settled because its creation time is old', function (): void {
    $event = eventsRegistrarOutboxRow();
    $database = app(VaulterDatabase::class);
    $database->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::EVENTS))
        ->where('event_id', $event['event_id'])
        ->update(['created_at' => now()->subDay(), 'staged_at' => null]);

    expect(app(OutboxStore::class)->settledSeq(CarbonImmutable::now()))->toBe(0)
        ->and(OutboxEvent::query()->where('event_id', $event['event_id'])->sole()->staged_at)->toBeNull();
});

it('preserves fractional staging time when deciding the settle cutoff', function (): void {
    $staged = CarbonImmutable::parse('2026-11-14T12:00:04.900000Z');
    $this->travelTo($staged);
    $database = app(VaulterDatabase::class);
    $event = new OutboxTimestampProbeEvent;

    try {
        $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
        $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();

        expect($row->staged_at?->format('Y-m-d H:i:s.u'))->toBe('2026-11-14 12:00:04.900000')
            ->and(app(OutboxStore::class)->settledSeq(CarbonImmutable::parse('2026-11-14T12:00:04.500000Z')))->toBe(0)
            ->and(app(OutboxStore::class)->settledSeq(CarbonImmutable::parse('2026-11-14T12:00:04.000000Z')))->toBe(0)
            ->and($database->connection()->table($row->getTable())->where('staged_at', '<', '2026-11-14 12:00:04.500000')->count())->toBe(0)
            ->and(app(OutboxStore::class)->settledSeq(CarbonImmutable::parse('2026-11-14T12:00:05Z')))->toBe($row->outbox_seq);
    } finally {
        $this->travelBack();
    }
});

it('stores and reads staging as UTC across a repeated local DST hour without changing the occurrence time', function (string $instant): void {
    $local = CarbonImmutable::parse($instant)->setTimezone('America/New_York');
    eventsRegistrarInTimezone('America/New_York', function () use ($local): void {
        $this->travelTo($local);
        $database = app(VaulterDatabase::class);
        $event = new OutboxTimestampProbeEvent(occurredAt: $local);

        try {
            $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
            $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();

            expect($row->getRawOriginal('created_at'))->toBe($local->format('Y-m-d H:i:s'))
                ->and($row->getRawOriginal('staged_at'))->toBe($local->utc()->format('Y-m-d H:i:s.u'))
                ->and($row->staged_at?->getTimestamp())->toBe($local->getTimestamp())
                ->and($row->staged_at?->format('u'))->toBe('900123')
                ->and($row->staged_at?->timezoneName)->toBe('UTC')
                ->and($row->payload['time'])->toBe($local->utc()->format('Y-m-d\TH:i:s.v\Z'));
        } finally {
            $this->travelBack();
        }
    });
})->with(['before the fall-back' => ['2026-11-01T05:59:59.900123Z'], 'after the fall-back' => ['2026-11-01T06:00:01.900123Z']]);

it('keeps UTC staging unchanged between MySQL writer and reader session timezones', function (): void {
    $database = app(VaulterDatabase::class);
    $connection = $database->connection();
    if ($connection->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL converts TIMESTAMP values through the session timezone.');
    }
    $previousTimezone = $connection->scalar('SELECT @@SESSION.time_zone');
    $staged = CarbonImmutable::parse('2026-11-01T06:00:01.900123Z');
    $this->travelTo($staged);
    $event = new OutboxTimestampProbeEvent(occurredAt: $staged);

    try {
        $connection->statement('SET SESSION time_zone = ?', ['+02:00']);
        $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
        $written = OutboxEvent::query()->where('event_id', $event->eventId)->sole();
        $connection->statement('SET SESSION time_zone = ?', ['+00:00']);
        $read = $written->fresh();

        expect(app(OutboxStore::class)->settledSeq($staged->addSecond()->subMinutes(5)))->toBe(0)
            ->and($written->getRawOriginal('staged_at'))->toBe('2026-11-01 06:00:01.900123')
            ->and($read->getRawOriginal('staged_at'))->toBe('2026-11-01 06:00:01.900123')
            ->and($read->staged_at?->format('Y-m-d H:i:s.uP'))->toBe('2026-11-01 06:00:01.900123+00:00')
            ->and(app(OutboxStore::class)->settledSeq($staged->addMicrosecond()))->toBe($read->outbox_seq)
            ->and($read->payload)->toBe($written->payload)
            ->and($read->payload['time'])->toBe($staged->format('Y-m-d\TH:i:s.v\Z'));
    } finally {
        $connection->statement('SET SESSION time_zone = ?', [$previousTimezone]);
        $this->travelBack();
    }
});

it('compares UTC staging against absolute cutoffs with all microseconds through a DST fall-back', function (): void {
    eventsRegistrarInTimezone('America/New_York', function (): void {
        $staged = CarbonImmutable::parse('2026-11-01T06:00:01.900123Z');
        $this->travelTo($staged->setTimezone('America/New_York'));
        $database = app(VaulterDatabase::class);

        try {
            $event = eventsRegistrarOutboxRow();
            $database->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::EVENTS))
                ->where('event_id', $event['event_id'])
                ->update(['staged_at' => $staged->format('Y-m-d H:i:s.u')]);

            foreach ([
                'fresh after the repeated hour' => ['2026-11-01T05:55:02.900123Z', false],
                'one microsecond before staging' => ['2026-11-01T06:00:01.900122Z', false],
                'equal to staging' => ['2026-11-01T06:00:01.900123Z', false],
                'one microsecond after staging' => ['2026-11-01T06:00:01.900124Z', true],
                'settled after the window' => ['2026-11-01T06:05:02.900123Z', true],
            ] as $label => [$instant, $settled]) {
                $cutoff = CarbonImmutable::parse($instant)->setTimezone('America/New_York');
                expect(app(OutboxStore::class)->settledSeq($cutoff))->toBe($settled ? $event['outbox_seq'] : 0, $label)
                    ->and($cutoff->timezoneName)->toBe('America/New_York');
            }
        } finally {
            $this->travelBack();
        }
    });
});

it('keeps a UTC staging model write within the same second precise without changing created_at', function (): void {
    $staged = CarbonImmutable::parse('2026-11-01T06:00:01.900123Z')->setTimezone('America/New_York');
    eventsRegistrarInTimezone('America/New_York', function () use ($staged): void {
        $this->travelTo($staged);
        $database = app(VaulterDatabase::class);
        $event = new OutboxTimestampProbeEvent(occurredAt: $staged);

        try {
            $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
            $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();
            $createdAt = $row->getRawOriginal('created_at');
            $payload = $row->payload;
            $database->write(static fn (): bool => $row->forceFill(['staged_at' => $staged->addMicrosecond()])->save());
            $fresh = $row->fresh();

            expect($fresh->getRawOriginal('staged_at'))->toBe('2026-11-01 06:00:01.900124')
                ->and($fresh->staged_at?->format('Y-m-d H:i:s.uP'))->toBe('2026-11-01 06:00:01.900124+00:00')
                ->and($fresh->getRawOriginal('created_at'))->toBe($createdAt)
                ->and($fresh->payload)->toBe($payload);
        } finally {
            $this->travelBack();
        }
    });
});

it('does not refresh a stored occurrence or its timestamp on replay', function (): void {
    $started = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $this->travelTo($started);
    $database = app(VaulterDatabase::class);
    $event = new OutboxTimestampProbeEvent;
    $active = false;
    $updates = 0;
    $connection = $database->connection();
    $wrappedTable = $connection->getQueryGrammar()->wrapTable(app(DatabaseConfig::class)->table(OutboxStore::EVENTS));
    $connection->beforeExecuting(static function (string $sql) use (&$active, &$updates, $wrappedTable): void {
        if ($active && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, $wrappedTable)) {
            $updates++;
        }
    });

    try {
        $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
        $before = OutboxEvent::query()->where('event_id', $event->eventId)->sole()->getRawOriginal();
        $this->travel(10)->seconds();
        $active = true;
        $replay = $database->transaction(fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter($database)));

        expect($replay)->toBe(SinkResult::Replayed)
            ->and($updates)->toBe(0)
            ->and(OutboxEvent::query()->where('event_id', $event->eventId)->sole()->getRawOriginal())->toBe($before)
            ->and(OutboxEvent::query()->where('event_id', $event->eventId)->count())->toBe(1);
    } finally {
        $active = false;
        $this->travelBack();
    }
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
    'varchar(64) and 65 multibyte characters' => ['string', str_repeat('я', 65)],
    'uuid column and a ulid' => ['uuid', (string) Str::ulid()],
    'bigint column and a ulid' => ['bigint', (string) Str::ulid()],
    'bigint column past the actual column maximum' => ['bigint', fn (): string => in_array(app(VaulterDatabase::class)->connection()->getDriverName(), ['mysql', 'mariadb'], true)
        ? '18446744073709551616' : '9223372036854775808'],
    'char(26) and a uuid' => ['ulid', (string) Str::uuid()],
    'char(26) and 27 multibyte characters' => ['ulid', str_repeat('я', 27)],
]);

it('stores a tenant id the host key column can hold, including null', function (string $hostKeys, ?string $tenant): void {
    $previous = config('vaulter.ids.host_keys');
    config()->set('vaulter.ids.host_keys', $hostKeys);

    try {
        $event = new HostKeyColumnProbeEvent($tenant);
        $result = app(VaulterDatabase::class)->transaction(
            fn (): SinkResult => app(OutboxWriter::class)->append($event, new SinkWriter(app(VaulterDatabase::class))),
        );
        $stored = OutboxEvent::query()->where('event_id', $event->eventId)->sole()->getRawOriginal('tenant_id');

        expect($result)->toBe(SinkResult::Stored)
            ->and($stored === null ? null : (string) $stored)->toBe($tenant);
    } finally {
        config()->set('vaulter.ids.host_keys', $previous);
    }
})->with([
    'null' => ['string', null],
    '64 bytes' => ['string', str_repeat('c', 64)],
    '64 multibyte characters' => ['string', str_repeat('я', 64)],
    'uuid' => ['uuid', '018f4c3a-7c3e-7b2a-8f1d-6a5b4c3d2e1f'],
    'ulid' => ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'short token in char(26)' => ['ulid', 'tenant-1'],
    '26 multibyte characters in char(26)' => ['ulid', str_repeat('я', 26)],
    'bigint' => ['bigint', '42'],
    'actual bigint column maximum' => ['bigint', fn (): string => in_array(app(VaulterDatabase::class)->connection()->getDriverName(), ['mysql', 'mariadb'], true)
        ? '18446744073709551615' : '9223372036854775807'],
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

function eventsRegistrarInTimezone(string $timezone, Closure $check): void
{
    $previous = date_default_timezone_get();
    $configured = config('app.timezone');
    try {
        config()->set('app.timezone', $timezone);
        date_default_timezone_set($timezone);
        $check();
    } finally {
        date_default_timezone_set($previous);
        config()->set('app.timezone', $configured);
    }
}

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
        'staged_at' => now(),
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

final readonly class OutboxTimestampProbeEvent extends DomainEvent
{
    public function __construct(private ?Closure $duringBody = null, ?CarbonImmutable $occurredAt = null)
    {
        parent::__construct(actor: new ActorRef(type: 'user', id: '1'), correlationId: 'corr-outbox-timestamp', occurredAt: $occurredAt);
    }

    public function type(): HostKeyColumnProbeType
    {
        return HostKeyColumnProbeType::Probe;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: null, driveId: null, profile: 'default');
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'node', id: '1');
    }

    public function data(): array
    {
        if ($this->duringBody !== null) {
            ($this->duringBody)();
        }

        return [];
    }
}
