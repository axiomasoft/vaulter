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
        ->and($exportedPayloads[0])->toContain('vaulter.outbox.deliver');

    // Wire-level protobuf verification: Span.links MUST be field 13 (NOT 11 events), Link.flags MUST be field 6 (NOT 5).
    $requestFields = otlpDecodeProtobufFields($exportedPayloads[0]);
    expect($requestFields)->toHaveKey(1);
    $resourceSpanFields = otlpDecodeProtobufFields((string) $requestFields[1][0]['value']);
    expect($resourceSpanFields)->toHaveKey(2);
    $scopeSpanFields = otlpDecodeProtobufFields((string) $resourceSpanFields[2][0]['value']);
    expect($scopeSpanFields)->toHaveKey(2);

    $deliverSpan = null;
    foreach ($scopeSpanFields[2] as $spanField) {
        expect($spanField['wireType'])->toBe(2);
        $decodedSpan = otlpDecodeProtobufFields((string) $spanField['value']);
        if (($decodedSpan[5][0]['value'] ?? null) === 'vaulter.outbox.deliver') {
            $deliverSpan = $decodedSpan;
            break;
        }
    }

    expect($deliverSpan)->toBeArray()
        ->and($deliverSpan)->not->toHaveKey(11)
        ->and($deliverSpan)->toHaveKey(13)
        ->and($deliverSpan[13])->toHaveCount(1)
        ->and($deliverSpan[13][0]['wireType'])->toBe(2);

    $linkFields = otlpDecodeProtobufFields((string) $deliverSpan[13][0]['value']);
    expect($linkFields)->toHaveKey(1)
        ->and($linkFields)->toHaveKey(2)
        ->and($linkFields)->not->toHaveKey(5)
        ->and($linkFields)->toHaveKey(6)
        ->and($linkFields[1][0]['wireType'])->toBe(2)
        ->and($linkFields[1][0]['value'])->toBe((string) hex2bin($parsed['traceId']))
        ->and($linkFields[2][0]['wireType'])->toBe(2)
        ->and($linkFields[2][0]['value'])->toBe((string) hex2bin($parsed['spanId']))
        ->and($linkFields[6][0]['wireType'])->toBe(5)
        ->and($linkFields[6][0]['value'])->toBe($parsed['flags']);
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

it('bounds OTLP DNS resolution within the 30ms export budget and caches resolved UDP DNS answers', function (): void {
    // 1. Slow/unresponsive UDP DNS server must never stall flush() past 30ms.
    $blackholeDns = stream_socket_server('udp://127.0.0.1:0', $dnsErrno, $dnsErrstr, STREAM_SERVER_BIND);
    expect($blackholeDns)->not->toBeFalse();
    $blackholeAddr = stream_socket_get_name($blackholeDns, false);
    expect($blackholeAddr)->toBeString();

    foreach ([30, 500] as $timeoutMs) {
        $recorder = new OtlpOpenTelemetryTraceRecorder(
            endpoint: 'http://slow-dns-'.$timeoutMs.'.invalid:4318/v1/traces',
            serviceName: 'vaulter-slow-dns-test',
            timeoutMs: $timeoutMs,
            dnsServers: [$blackholeAddr],
        );
        $recorder->span('vaulter.slow.dns', static fn (): bool => true);

        $started = hrtime(true);
        $recorder->flush();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        expect($elapsedMs)->toBeLessThan(35.0)
            ->and($recorder->bufferedCount())->toBe(0);
    }
    fclose($blackholeDns);

    // 2. Non-blocking UDP DNS resolves to collector IP and caches positive TTL across flushes.
    $collector = stream_socket_server('tcp://127.0.0.1:0', $colErrno, $colErrstr);
    expect($collector)->not->toBeFalse();
    $collectorAddr = stream_socket_get_name($collector, false);
    $collectorPort = (int) substr((string) $collectorAddr, strrpos((string) $collectorAddr, ':') + 1);

    $dnsScript = <<<'PHP'
$sock = @stream_socket_server('udp://127.0.0.1:0', $e, $s, STREAM_SERVER_BIND);
if ($sock === false) { exit(1); }
fwrite(STDOUT, (string) stream_socket_get_name($sock, false)."\n");
fflush(STDOUT);
$read = [$sock];
$w = null; $ex = null;
if (@stream_select($read, $w, $ex, 5) !== 1) { fclose($sock); exit(1); }
$pkt = @stream_socket_recvfrom($sock, 512, 0, $peer);
if (!is_string($pkt) || strlen($pkt) < 17) { fclose($sock); exit(1); }
$offset = 12;
$len = strlen($pkt);
while ($offset < $len) {
    $l = ord($pkt[$offset]);
    $offset++;
    if ($l === 0) { break; }
    $offset += $l;
}
$question = substr($pkt, 12, ($offset + 4) - 12);
$resp = substr($pkt, 0, 2)."\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00".$question
    ."\xC0\x0C\x00\x01\x00\x01".pack('N', 30)."\x00\x04".inet_pton('127.0.0.1');
@stream_socket_sendto($sock, $resp, 0, $peer);
fclose($sock);
PHP;
    $proc = proc_open(
        [PHP_BINARY, '-r', $dnsScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($proc)->not->toBeFalse();

    $domain = 'collector-cache-'.bin2hex(random_bytes(4)).'.test';
    try {
        fclose($pipes[0]);
        $dnsAddr = trim((string) fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect($dnsAddr)->toStartWith('127.0.0.1:');

        $recorder = new OtlpOpenTelemetryTraceRecorder(
            endpoint: "http://{$domain}:{$collectorPort}/v1/traces",
            serviceName: 'vaulter-dns-cache-test',
            timeoutMs: 200,
            dnsServers: [$dnsAddr],
        );
        $recorder->span('vaulter.dns.udp', static fn (): bool => true);
        $recorder->flush();

        $firstConn = @stream_socket_accept($collector, 1.0);
        expect($firstConn)->not->toBeFalse();
        $firstBody = stream_get_contents($firstConn);
        fclose($firstConn);
        expect($firstBody)->toContain("Host: {$domain}:{$collectorPort}")
            ->and($firstBody)->toContain('vaulter.dns.udp');

        // Child DNS server exits after handling 1 packet; second flush must succeed from TTL cache.
        expect(proc_close($proc))->toBe(0);
        $proc = null;

        $recorder->span('vaulter.dns.cached', static fn (): bool => true);
        $recorder->flush();

        $secondConn = @stream_socket_accept($collector, 1.0);
        expect($secondConn)->not->toBeFalse();
        $secondBody = stream_get_contents($secondConn);
        fclose($secondConn);
        expect($secondBody)->toContain('vaulter.dns.cached');
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        fclose($collector);
    }
});

it('resolves AAAA records over bounded UDP DNS and exports to IPv6-only collectors', function (): void {
    $collector = @stream_socket_server('tcp://[::1]:0', $colErrno, $colErrstr);
    if ($collector === false) {
        throw new RuntimeException('IPv6 loopback socket unavailable: '.$colErrstr);
    }
    $collectorAddr = stream_socket_get_name($collector, false);
    expect($collectorAddr)->toBeString();
    $collectorPort = (int) substr((string) $collectorAddr, strrpos((string) $collectorAddr, ':') + 1);

    // UDP DNS server answering A with NODATA (ancount=0) and AAAA with ::1.
    $dnsScript = <<<'PHP'
$sock = @stream_socket_server('udp://127.0.0.1:0', $e, $s, STREAM_SERVER_BIND);
if ($sock === false) { exit(1); }
fwrite(STDOUT, (string) stream_socket_get_name($sock, false)."\n");
fflush(STDOUT);
$seen = [];
$deadline = microtime(true) + 5.0;
while (count($seen) < 2 && microtime(true) < $deadline) {
    $read = [$sock];
    $w = null; $ex = null;
    if (@stream_select($read, $w, $ex, 1) !== 1) { continue; }
    $pkt = @stream_socket_recvfrom($sock, 512, 0, $peer);
    if (!is_string($pkt) || strlen($pkt) < 17) { continue; }
    $offset = 12;
    $len = strlen($pkt);
    while ($offset < $len) {
        $l = ord($pkt[$offset]);
        $offset++;
        if ($l === 0) { break; }
        $offset += $l;
    }
    if ($offset + 4 > $len) { continue; }
    $qtype = unpack('n', substr($pkt, $offset, 2))[1] ?? 0;
    $question = substr($pkt, 12, ($offset + 4) - 12);
    if ($qtype === 28) {
        $resp = substr($pkt, 0, 2)."\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00".$question
            ."\xC0\x0C\x00\x1C\x00\x01".pack('N', 30)."\x00\x10".inet_pton('::1');
    } else {
        $resp = substr($pkt, 0, 2)."\x81\x80\x00\x01\x00\x00\x00\x00\x00\x00".$question;
    }
    @stream_socket_sendto($sock, $resp, 0, $peer);
    $seen[$qtype] = true;
}
fclose($sock);
PHP;
    $proc = proc_open(
        [PHP_BINARY, '-r', $dnsScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($proc)->not->toBeFalse();

    $domain = 'collector-v6-'.bin2hex(random_bytes(4)).'.test';
    try {
        fclose($pipes[0]);
        $dnsAddr = trim((string) fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect($dnsAddr)->toStartWith('127.0.0.1:');

        $recorder = new OtlpOpenTelemetryTraceRecorder(
            endpoint: "http://{$domain}:{$collectorPort}/v1/traces",
            serviceName: 'vaulter-dns-v6-test',
            timeoutMs: 200,
            dnsServers: [$dnsAddr],
        );
        $recorder->span('vaulter.dns.aaaa', static fn (): bool => true);
        $recorder->flush();

        $conn = @stream_socket_accept($collector, 1.0);
        expect($conn)->not->toBeFalse();
        $body = stream_get_contents($conn);
        fclose($conn);
        expect($body)->toContain("Host: {$domain}:{$collectorPort}")
            ->and($body)->toContain('vaulter.dns.aaaa');

        expect(proc_close($proc))->toBe(0);
        $proc = null;

        // Second flush succeeds via cached AAAA resolution after DNS process exited.
        $recorder->span('vaulter.dns.aaaa.cached', static fn (): bool => true);
        $recorder->flush();

        $cachedConn = @stream_socket_accept($collector, 1.0);
        expect($cachedConn)->not->toBeFalse();
        $cachedBody = stream_get_contents($cachedConn);
        fclose($cachedConn);
        expect($cachedBody)->toContain('vaulter.dns.aaaa.cached');
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        fclose($collector);
    }
});

it('caches both A and AAAA DNS records and falls back to IPv6 when IPv4 connect fails', function (): void {
    $collector = @stream_socket_server('tcp://[::1]:0', $colErrno, $colErrstr);
    if ($collector === false) {
        throw new RuntimeException('IPv6 loopback socket unavailable: '.$colErrstr);
    }
    $collectorAddr = stream_socket_get_name($collector, false);
    expect($collectorAddr)->toBeString();
    $collectorPort = (int) substr((string) $collectorAddr, strrpos((string) $collectorAddr, ':') + 1);

    // UDP DNS server returning A (127.0.0.1 — unreachable for IPv6-only collectorPort) AND AAAA (::1).
    $dnsScript = <<<'PHP'
$sock = @stream_socket_server('udp://127.0.0.1:0', $e, $s, STREAM_SERVER_BIND);
if ($sock === false) { exit(1); }
fwrite(STDOUT, (string) stream_socket_get_name($sock, false)."\n");
fflush(STDOUT);
$seen = [];
$deadline = microtime(true) + 5.0;
while (count($seen) < 2 && microtime(true) < $deadline) {
    $read = [$sock];
    $w = null; $ex = null;
    if (@stream_select($read, $w, $ex, 1) !== 1) { continue; }
    $pkt = @stream_socket_recvfrom($sock, 512, 0, $peer);
    if (!is_string($pkt) || strlen($pkt) < 17) { continue; }
    $offset = 12;
    $len = strlen($pkt);
    while ($offset < $len) {
        $l = ord($pkt[$offset]);
        $offset++;
        if ($l === 0) { break; }
        $offset += $l;
    }
    if ($offset + 4 > $len) { continue; }
    $qtype = unpack('n', substr($pkt, $offset, 2))[1] ?? 0;
    $question = substr($pkt, 12, ($offset + 4) - 12);
    if ($qtype === 1) {
        $resp = substr($pkt, 0, 2)."\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00".$question
            ."\xC0\x0C\x00\x01\x00\x01".pack('N', 30)."\x00\x04".inet_pton('127.0.0.1');
    } elseif ($qtype === 28) {
        $resp = substr($pkt, 0, 2)."\x81\x80\x00\x01\x00\x01\x00\x00\x00\x00".$question
            ."\xC0\x0C\x00\x1C\x00\x01".pack('N', 30)."\x00\x10".inet_pton('::1');
    } else {
        continue;
    }
    @stream_socket_sendto($sock, $resp, 0, $peer);
    $seen[$qtype] = true;
}
fclose($sock);
PHP;
    $proc = proc_open(
        [PHP_BINARY, '-r', $dnsScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($proc)->not->toBeFalse();

    $domain = 'collector-dualstack-'.bin2hex(random_bytes(4)).'.test';
    try {
        fclose($pipes[0]);
        $dnsAddr = trim((string) fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect($dnsAddr)->toStartWith('127.0.0.1:');

        $recorder = new OtlpOpenTelemetryTraceRecorder(
            endpoint: "http://{$domain}:{$collectorPort}/v1/traces",
            serviceName: 'vaulter-dualstack-test',
            timeoutMs: 250,
            dnsServers: [$dnsAddr],
        );
        $recorder->span('vaulter.dns.dualstack.live', static fn (): bool => true);
        $recorder->flush();

        $conn = @stream_socket_accept($collector, 1.0);
        expect($conn)->not->toBeFalse();
        $body = stream_get_contents($conn);
        fclose($conn);
        expect($body)->toContain("Host: {$domain}:{$collectorPort}")
            ->and($body)->toContain('vaulter.dns.dualstack.live');

        expect(proc_close($proc))->toBe(0);
        $proc = null;

        // Second flush must still fall back from cached A (127.0.0.1) to cached AAAA (::1) with DNS stopped.
        $recorder->span('vaulter.dns.dualstack.cached', static fn (): bool => true);
        $recorder->flush();

        $cachedConn = @stream_socket_accept($collector, 1.0);
        expect($cachedConn)->not->toBeFalse();
        $cachedBody = stream_get_contents($cachedConn);
        fclose($cachedConn);
        expect($cachedBody)->toContain('vaulter.dns.dualstack.cached');
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        fclose($collector);
    }
});

it('reserves export budget across candidate addresses during TLS handshake and falls back to IPv6 when first IPv4 TLS peer stalls', function (): void {
    $pemFile = tempnam(sys_get_temp_dir(), 'otlp-tls-fallback-');
    expect($pemFile)->toBeString();
    $certFile = $pemFile.'.crt';
    $domain = 'collector-tls-fallback.test';

    $opensslCmd = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 1 -subj /CN=%s -addext subjectAltName=DNS:%s 2>/dev/null',
        escapeshellarg($pemFile),
        escapeshellarg($certFile),
        $domain,
        $domain,
    );
    exec($opensslCmd, $unusedOut, $opensslExit);
    expect($opensslExit)->toBe(0);
    $certPem = file_get_contents($certFile);
    expect($certPem)->toBeString()->and($certPem)->not->toBe('');
    file_put_contents($pemFile, $certPem, FILE_APPEND);

    $tlsServerScript = <<<'PHP'
$pemFile = $argv[1];
$ctx = stream_context_create([
    'ssl' => [
        'local_cert' => $pemFile,
        'passphrase' => '',
        'allow_self_signed' => true,
        'verify_peer' => false,
    ],
]);
$server = @stream_socket_server('tcp://[::1]:0', $e, $s, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) { exit(1); }
$addr = (string) stream_socket_get_name($server, false);
$port = (int) substr($addr, strrpos($addr, ':') + 1);
fwrite(STDOUT, $port."\n");
fflush(STDOUT);
$conn = @stream_socket_accept($server, 5.0);
if ($conn === false) { fclose($server); exit(1); }
stream_set_blocking($conn, true);
$cryptoOk = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
if ($cryptoOk !== true) { fclose($conn); fclose($server); exit(1); }
$req = '';
$deadline = microtime(true) + 2.0;
while (!feof($conn) && microtime(true) < $deadline) {
    $chunk = @fread($conn, 8192);
    if (!is_string($chunk) || $chunk === '') { break; }
    $req .= $chunk;
    if (str_contains($req, "\r\n\r\n")) {
        [$hdr, $body] = explode("\r\n\r\n", $req, 2);
        if (preg_match('/Content-Length:\s*(\d+)/i', $hdr, $m) && strlen($body) >= (int) $m[1]) {
            break;
        }
    }
}
@fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
@fclose($conn);
fclose($server);
fwrite(STDOUT, base64_encode($req)."\n");
fflush(STDOUT);
PHP;

    $proc = null;
    $stalledIpv4 = null;
    $stalledClient = null;
    try {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $proc = proc_open(
                [PHP_BINARY, '-r', $tlsServerScript, $pemFile],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            expect($proc)->not->toBeFalse();
            fclose($pipes[0]);
            $portLine = trim((string) fgets($pipes[1]));
            expect($portLine)->toMatch('/^\d+$/');
            $collectorPort = (int) $portLine;

            $stalledIpv4 = @stream_socket_server("tcp://127.0.0.1:{$collectorPort}", $v4Err, $v4Str);
            if ($stalledIpv4 !== false) {
                break;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
            $proc = null;
        }

        expect($stalledIpv4)->not->toBeFalse();

        $recorder = new OtlpOpenTelemetryTraceRecorder(
            endpoint: "https://{$domain}:{$collectorPort}/v1/traces",
            serviceName: 'vaulter-tls-fallback-test',
            timeoutMs: 200,
            dnsResolver: static fn (): array => ['127.0.0.1', '::1'],
            certificateFile: $pemFile,
        );
        $recorder->span('vaulter.tls.stalled_fallback', static fn (): bool => true);

        $started = hrtime(true);
        $recorder->flush();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        // First IPv4 peer accepted TCP and initiated TLS ClientHello before stalling on handshake.
        $stalledClient = @stream_socket_accept($stalledIpv4, 0.5);
        expect($stalledClient)->not->toBeFalse();
        stream_set_timeout($stalledClient, 0, 200_000);
        $clientHello = @fread($stalledClient, 16);
        expect($clientHello)->toBeString()
            ->and(strlen($clientHello))->toBeGreaterThanOrEqual(2)
            ->and(substr($clientHello, 0, 2))->toBe("\x16\x03");

        $encodedReq = trim((string) fgets($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($proc))->toBe(0, (string) $stderr);
        $proc = null;

        $receivedReq = base64_decode($encodedReq, true);
        expect($receivedReq)->toBeString()
            ->and($receivedReq)->toStartWith("POST /v1/traces HTTP/1.1\r\n")
            ->and($receivedReq)->toContain("Host: {$domain}:{$collectorPort}")
            ->and($receivedReq)->toContain('vaulter.tls.stalled_fallback')
            ->and($elapsedMs)->toBeLessThan(250.0)
            ->and($recorder->bufferedCount())->toBe(0);
    } finally {
        if (is_resource($stalledClient)) {
            @fclose($stalledClient);
        }
        if (is_resource($stalledIpv4)) {
            @fclose($stalledIpv4);
        }
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        @unlink($pemFile);
        @unlink($certFile);
    }
});

it('skips OutboxRelay traceparent validation and container tracer lookup when tracing is disabled', function (): void {
    config()->set('vaulter.observability.tracing.enabled', false);

    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $arguments['contributions'][OutboxConsumer::class] = [
        new SlotContribution(OutboxConsumer::class, OtlpRelayProbeConsumer::ID, 'core', OtlpRelayProbeConsumer::class, 0, null),
    ];
    $kernel = new Kernel($this->app);
    $kernel->seal(new CompiledModules(...$arguments));
    $this->app->instance(Kernel::class, $kernel);

    $consumer = new OtlpRelayProbeConsumer;
    $this->app->instance(OtlpRelayProbeConsumer::class, $consumer);

    $this->app->forgetInstance(TraceRecorder::class);
    $this->app->bind(TraceRecorder::class, static function (): TraceRecorder {
        throw new RuntimeException('TraceRecorder should not be resolved when tracing is disabled');
    });

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

    // Also verify vaulter:relay command injection skips TraceRecorder resolution when disabled.
    $secondEventId = 'evt-'.Str::uuid7()->toString();
    $database->transaction(fn () => app(OutboxWriter::class)->append(new OtlpOutboxProbeEvent($secondEventId), new SinkWriter($database)));
    DB::table(app(DatabaseOptions::class)->table(OutboxStore::EVENTS))
        ->where('event_id', $secondEventId)
        ->update(['payload' => json_encode(['data' => ['node_id' => 'node-otel'], '_traceparent' => '00-0123456789abcdef0123456789abcdef-89abcdef01234567-01'], JSON_THROW_ON_ERROR)]);

    $this->artisan('vaulter:relay', ['--once' => true, '--consumer' => OtlpRelayProbeConsumer::ID])
        ->assertSuccessful();

    expect($consumer->seenEnvelopes)->toHaveCount(2)
        ->and($consumer->seenEnvelopes[1]->payload)->not->toHaveKey('_traceparent');
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

/**
 * @return array<int, list<array{wireType: int, value: int|string}>>
 */
function otlpDecodeProtobufFields(string $binary): array
{
    $offset = 0;
    $length = strlen($binary);
    $fields = [];

    $readVarint = static function () use ($binary, $length, &$offset): int {
        $value = 0;
        $shift = 0;
        while ($offset < $length) {
            $byte = ord($binary[$offset]);
            $offset++;
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new RuntimeException('Protobuf varint overflow.');
            }
        }

        throw new RuntimeException('Unexpected EOF while reading protobuf varint.');
    };

    while ($offset < $length) {
        $tag = $readVarint();
        $fieldNumber = $tag >> 3;
        $wireType = $tag & 0x07;

        $value = match ($wireType) {
            0 => $readVarint(),
            1 => (static function () use ($binary, $length, &$offset): string {
                if ($offset + 8 > $length) {
                    throw new RuntimeException('Unexpected EOF while reading fixed64.');
                }
                $bytes = substr($binary, $offset, 8);
                $offset += 8;

                return $bytes;
            })(),
            2 => (static function () use ($binary, $length, &$offset, $readVarint): string {
                $len = $readVarint();
                if ($offset + $len > $length) {
                    throw new RuntimeException('Unexpected EOF while reading length-delimited field.');
                }
                $bytes = substr($binary, $offset, $len);
                $offset += $len;

                return $bytes;
            })(),
            5 => (static function () use ($binary, $length, &$offset): int {
                if ($offset + 4 > $length) {
                    throw new RuntimeException('Unexpected EOF while reading fixed32.');
                }
                /** @var array{1: int} $unpacked */
                $unpacked = unpack('V', substr($binary, $offset, 4));
                $offset += 4;

                return $unpacked[1];
            })(),
            default => throw new RuntimeException('Unsupported protobuf wire type: '.$wireType),
        };

        $fields[$fieldNumber][] = ['wireType' => $wireType, 'value' => $value];
    }

    return $fields;
}
