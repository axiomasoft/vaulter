<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Attributes\EventSpec;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Concerns\DescribesEvent;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Events\Outbox\OutboxWriter;
use Vaulter\Events\SinkWriter;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Observability\OtlpOpenTelemetryTraceRecorder;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

it('registers the kernel config, database and observability seams', function (): void {
    (new BindingsRegistrar)->register($this->app);

    expect(app(VaulterOptions::class))->toBe(app(VaulterOptions::class))
        ->and(app(VaulterDatabase::class))->toBe(app(VaulterDatabase::class))
        ->and(app(MetricsRecorder::class))->toBeInstanceOf(MetricsRecorder::class);
});

it('keeps NullTraceRecorder when tracing is disabled even if recorder is otlp', function (): void {
    config()->set('vaulter.observability.tracing.enabled', false);
    config()->set('vaulter.observability.tracing.recorder', 'otlp');
    $this->app->forgetInstance(TraceRecorder::class);

    expect(app(TraceRecorder::class))->toBeInstanceOf(NullTraceRecorder::class);
});

it('registers OtlpOpenTelemetryTraceRecorder when tracing is enabled with otlp config', function (): void {
    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', 'otlp');
    $this->app->forgetInstance(TraceRecorder::class);

    expect(app(TraceRecorder::class))->toBeInstanceOf(OtlpOpenTelemetryTraceRecorder::class);
});

it('buffers spans on the hot path and exports OTLP protobuf without throwing on transport error', function (): void {
    $captured = [];
    $recorder = new OtlpOpenTelemetryTraceRecorder(
        endpoint: 'http://collector.test:4318/v1/traces',
        serviceName: 'vaulter-spec',
        timeoutMs: 100,
        headers: ['x-otel-token' => 'tok'],
        transport: static function (string $endpoint, string $payload, array $headers, int $timeoutMs) use (&$captured): void {
            $captured[] = compact('endpoint', 'payload', 'headers', 'timeoutMs');
        },
    );

    $result = $recorder->withTraceparent('00-0123456789abcdef0123456789abcdef-89abcdef01234567-01', function () use ($recorder): int {
        return $recorder->span('vaulter.test.root', function () use ($recorder): int {
            expect($recorder->currentTraceparent())->toStartWith('00-0123456789abcdef0123456789abcdef-');

            return 42;
        }, ['vaulter.target' => 'core', 'vaulter.nested' => false, 'vaulter.count' => 2]);
    });

    expect($result)->toBe(42)
        ->and($recorder->bufferedCount())->toBe(1)
        ->and($captured)->toBe([]);

    $recorder->flush();

    expect($recorder->bufferedCount())->toBe(0)
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['endpoint'])->toBe('http://collector.test:4318/v1/traces')
        ->and($captured[0]['headers']['Content-Type'])->toBe('application/x-protobuf')
        ->and($captured[0]['headers']['x-otel-token'])->toBe('tok')
        ->and($captured[0]['payload'])->toContain('vaulter-spec')
        ->and($captured[0]['payload'])->toContain('vaulter.test.root')
        ->and($captured[0]['payload'])->toContain((string) hex2bin('0123456789abcdef0123456789abcdef'))
        ->and($captured[0]['payload'])->toContain((string) hex2bin('89abcdef01234567'));

    $failing = new OtlpOpenTelemetryTraceRecorder(
        transport: static function (): void {
            throw new RuntimeException('collector down');
        },
    );
    expect($failing->span('vaulter.fail.open', static fn (): string => 'ok'))->toBe('ok');
    $failing->flush();
    expect($failing->bufferedCount())->toBe(0);
});

it('captures traceparent in outbox payload metadata and creates an OTel Span Link in OutboxRelay without leaking into OutboxEnvelope', function (): void {
    $exportedPayloads = [];
    $recorder = new OtlpOpenTelemetryTraceRecorder(
        serviceName: 'vaulter-outbox-test',
        transport: static function (string $endpoint, string $payload) use (&$exportedPayloads): void {
            $exportedPayloads[] = $payload;
        },
    );

    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', OtlpOpenTelemetryTraceRecorder::class);
    $this->app->instance(OtlpOpenTelemetryTraceRecorder::class, $recorder);
    $this->app->forgetInstance(TraceRecorder::class);

    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $arguments['contributions'][OutboxConsumer::class] = [
        new SlotContribution(OutboxConsumer::class, OtlpRelayProbeConsumer::ID, 'core', OtlpRelayProbeConsumer::class, 0, null),
    ];
    $kernel = new Kernel($this->app);
    $kernel->seal(new CompiledModules(...$arguments));
    $this->app->instance(Kernel::class, $kernel);

    $consumer = new OtlpRelayProbeConsumer;
    $this->app->instance(OtlpRelayProbeConsumer::class, $consumer);

    $eventId = 'evt-'.Str::uuid7()->toString();
    $event = new OtlpOutboxProbeEvent($eventId);

    $capturedTraceparent = null;
    $recorder->span('vaulter.producer.test', function () use ($event, $recorder, &$capturedTraceparent): void {
        $capturedTraceparent = $recorder->currentTraceparent();
        $database = app(VaulterDatabase::class);
        $database->transaction(fn () => app(OutboxWriter::class)->append($event, new SinkWriter($database)));
    });

    $stored = OutboxEvent::query()->where('event_id', $eventId)->sole();
    expect($capturedTraceparent)->not->toBeNull()
        ->and($stored->traceparent())->toBe($capturedTraceparent)
        ->and($stored->payload['_traceparent'] ?? null)->toBe($capturedTraceparent)
        ->and($stored->toEnvelope()->payload)->not->toHaveKey('_traceparent')
        ->and($stored->toEnvelope()->payload['data'])->toBe(['node_id' => 'node-otel']);

    $parsed = OtlpOpenTelemetryTraceRecorder::parseTraceparent($capturedTraceparent);
    expect($parsed)->not->toBeNull();

    $finished = app(OutboxRelay::class)->pass(only: OtlpRelayProbeConsumer::ID);
    $recorder->flush();

    expect($finished)->toBe([OtlpRelayProbeConsumer::ID => 1])
        ->and($consumer->seenEnvelopes)->toHaveCount(1)
        ->and($consumer->seenEnvelopes[0]->payload)->not->toHaveKey('_traceparent')
        ->and($consumer->seenEnvelopes[0]->payload['data'])->toBe(['node_id' => 'node-otel'])
        ->and($exportedPayloads)->toHaveCount(1)
        ->and($exportedPayloads[0])->toContain('vaulter.outbox.deliver')
        ->and($exportedPayloads[0])->toContain((string) hex2bin($parsed['traceId']))
        ->and($exportedPayloads[0])->toContain((string) hex2bin($parsed['spanId']));
});

it('redacts raw exception messages from exported OTLP spans', function (): void {
    $exportedPayloads = [];
    $recorder = new OtlpOpenTelemetryTraceRecorder(
        serviceName: 'vaulter-redact-test',
        transport: static function (string $endpoint, string $payload) use (&$exportedPayloads): void {
            $exportedPayloads[] = $payload;
        },
    );

    $secretMessage = 'SQLSTATE[23505]: duplicate key value (secret-sql-binding-token-xyz)';
    expect(fn () => $recorder->span('vaulter.redact.test', static function () use ($secretMessage): void {
        throw new RuntimeException($secretMessage, 42);
    }))->toThrow(RuntimeException::class);

    $recorder->flush();

    expect($exportedPayloads)->toHaveCount(1)
        ->and($exportedPayloads[0])->not->toContain($secretMessage)
        ->and($exportedPayloads[0])->not->toContain('secret-sql-binding-token-xyz')
        ->and($exportedPayloads[0])->toContain(RuntimeException::class)
        ->and($exportedPayloads[0])->toContain('error.type');
});

it('exports OTLP protobuf over non-blocking sockets without stalling on stalled collectors', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();

    $recorder = new OtlpOpenTelemetryTraceRecorder(
        endpoint: 'http://'.$address.'/v1/traces',
        serviceName: 'vaulter-socket-test',
        timeoutMs: 150,
    );
    $recorder->span('vaulter.socket.export', static fn (): bool => true);

    $started = hrtime(true);
    $recorder->flush();
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    $client = @stream_socket_accept($server, 1.0);
    expect($client)->not->toBeFalse();
    stream_set_timeout($client, 1);
    $rawRequest = '';
    while (!feof($client)) {
        $chunk = fread($client, 8192);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $rawRequest .= $chunk;
    }
    fclose($client);
    fclose($server);

    expect($elapsedMs)->toBeLessThan(150.0)
        ->and($rawRequest)->toStartWith("POST /v1/traces HTTP/1.1\r\n")
        ->and($rawRequest)->toContain('Content-Type: application/x-protobuf')
        ->and($rawRequest)->toContain('vaulter.socket.export');

    // Stalled collector (accepts connection, never sends HTTP response) must not stall flush().
    $stalledServer = stream_socket_server('tcp://127.0.0.1:0', $errno2, $errstr2);
    expect($stalledServer)->not->toBeFalse();
    $stalledAddress = stream_socket_get_name($stalledServer, false);
    $stalledRecorder = new OtlpOpenTelemetryTraceRecorder(
        endpoint: 'http://'.$stalledAddress.'/v1/traces',
        serviceName: 'vaulter-stalled-test',
        timeoutMs: 500,
    );
    $stalledRecorder->span('vaulter.socket.stalled', static fn (): bool => true);

    $stallStart = hrtime(true);
    $stalledRecorder->flush();
    $stallElapsedMs = (hrtime(true) - $stallStart) / 1_000_000;
    fclose($stalledServer);

    expect($stallElapsedMs)->toBeLessThan(200.0)
        ->and($stalledRecorder->bufferedCount())->toBe(0);
});

it('releases scoped OtlpOpenTelemetryTraceRecorder instances across container scope resets', function (): void {
    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', 'otlp');
    $this->app->forgetScopedInstances();

    $first = app(TraceRecorder::class);
    expect($first)->toBeInstanceOf(OtlpOpenTelemetryTraceRecorder::class);
    $weakFirst = WeakReference::create($first);
    unset($first);

    $this->app->forgetScopedInstances();
    gc_collect_cycles();

    expect($weakFirst->get())->toBeNull();
});

it('skips OutboxRelay traceparent validation when tracing is disabled', function (): void {
    config()->set('vaulter.observability.tracing.enabled', false);
    $this->app->forgetInstance(TraceRecorder::class);

    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $arguments['contributions'][OutboxConsumer::class] = [
        new SlotContribution(OutboxConsumer::class, OtlpRelayProbeConsumer::ID, 'core', OtlpRelayProbeConsumer::class, 0, null),
    ];
    $kernel = new Kernel($this->app);
    $kernel->seal(new CompiledModules(...$arguments));
    $this->app->instance(Kernel::class, $kernel);

    $consumer = new OtlpRelayProbeConsumer;
    $this->app->instance(OtlpRelayProbeConsumer::class, $consumer);

    $eventId = 'evt-'.Str::uuid7()->toString();
    $event = new OtlpOutboxProbeEvent($eventId);
    $database = app(VaulterDatabase::class);
    $database->transaction(fn () => app(OutboxWriter::class)->append($event, new SinkWriter($database)));

    // Simulate an event carrying _traceparent while relay runs with tracing disabled.
    DB::table(app(DatabaseOptions::class)->table(OutboxStore::EVENTS))
        ->where('event_id', $eventId)
        ->update(['payload' => json_encode(['data' => ['node_id' => 'node-otel'], '_traceparent' => '00-0123456789abcdef0123456789abcdef-89abcdef01234567-01'], JSON_THROW_ON_ERROR)]);

    $finished = app(OutboxRelay::class)->pass(only: OtlpRelayProbeConsumer::ID);

    expect($finished)->toBe([OtlpRelayProbeConsumer::ID => 1])
        ->and($consumer->seenEnvelopes)->toHaveCount(1)
        ->and($consumer->seenEnvelopes[0]->payload)->not->toHaveKey('_traceparent');
});

it('configures PostgreSQL autovacuum storage parameters on v_outbox and v_outbox_deliveries', function (): void {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'pgsql') {
        expect($connection->getDriverName())->not->toBe('pgsql');

        return;
    }

    $tables = app(DatabaseOptions::class);
    foreach ([OutboxStore::EVENTS, OutboxStore::DELIVERIES] as $logical) {
        $tableName = $tables->table($logical);
        $row = $connection->selectOne(
            "SELECT array_to_string(reloptions, ',') AS reloptions FROM pg_class WHERE relname = ? AND relnamespace = current_schema()::regnamespace",
            [$tableName],
        );

        expect($row)->not->toBeNull();
        $options = (string) ($row->reloptions ?? '');
        expect($options)->toContain('autovacuum_vacuum_scale_factor=0')
            ->and($options)->toContain('autovacuum_vacuum_threshold=2000');
    }
});

enum OtlpOutboxProbeType: string implements EventTypeContract
{
    use DescribesEvent;

    #[EventSpec(subject: 'node', data: ['node_id'], activity: false, external: true, journal: null, description: 'OTLP probe event.')]
    case Probe = 'core.probe.otlp_outbox';
}

final readonly class OtlpOutboxProbeEvent extends DomainEvent
{
    public function __construct(string $eventId)
    {
        parent::__construct(
            actor: new ActorRef(type: 'user', id: 1),
            correlationId: 'corr-otlp-probe',
            eventId: $eventId,
            occurredAt: CarbonImmutable::now(),
        );
    }

    public function type(): OtlpOutboxProbeType
    {
        return OtlpOutboxProbeType::Probe;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: null, driveId: null, profile: 'default');
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'node', id: 'node-otel');
    }

    public function data(): array
    {
        return ['node_id' => 'node-otel'];
    }
}

final class OtlpRelayProbeConsumer implements OutboxConsumer
{
    public const string ID = 'core.probe.otlp_relay';

    /** @var list<OutboxEnvelope> */
    public array $seenEnvelopes = [];

    public function id(): string
    {
        return self::ID;
    }

    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Durable;
    }

    public function subscribes(EventTypeContract $type): bool
    {
        return $type === OtlpOutboxProbeType::Probe;
    }

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult
    {
        $this->seenEnvelopes[] = $envelope;

        return ConsumeResult::done();
    }
}
