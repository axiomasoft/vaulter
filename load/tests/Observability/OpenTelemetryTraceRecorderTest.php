<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SDK\Trace\Behavior\SpanExporterTrait;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Load\Observability\RunScopedSpanExporter;
use Vaulter\Observability\NullTraceRecorder;

/**
 * @return array{0: OpenTelemetryTraceRecorder, 1: InMemoryExporter}
 */
function tracedRecorder(float $ratio): array
{
    $exporter = new InMemoryExporter;
    $recorder = OpenTelemetryTraceRecorder::create(exporter: $exporter, ratio: $ratio);

    return [$recorder, $exporter];
}

/**
 * @return list<array<string, mixed>>
 */
function exportedRows(InMemoryExporter $exporter): array
{
    return array_map(
        static fn ($span): array => RunScopedSpanExporter::row($span),
        $exporter->getSpans(),
    );
}

it('nests package spans under the http server span extracted from w3c traceparent', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);
    $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
    $parentId = '00f067aa0ba902b7';
    $request = Request::create('/api/vaulter/nodes', 'GET');
    $request->headers->set('traceparent', "00-{$traceId}-{$parentId}-01");
    $request->headers->set('tracestate', 'vendor=keep');

    $result = $recorder->traceServer(
        request: $request,
        next: function () use ($recorder): string {
            return $recorder->span(
                name: 'vaulter.action.webhook.relay',
                operation: fn (): string => 'relayed',
                attributes: [
                    'vaulter.target' => 'webhook.pipeline',
                    'vaulter.layer' => 'action',
                    'vaulter.outcome' => 'success',
                ],
            );
        },
    );
    $recorder->forceFlush();

    $rows = exportedRows($exporter);
    $server = collect($rows)->firstWhere('kind', 'SERVER');
    $child = collect($rows)->firstWhere('kind', 'INTERNAL');

    expect($result)->toBe('relayed')
        ->and($request->headers->get('tracestate'))->toBe('vendor=keep')
        ->and($rows)->toHaveCount(2)
        ->and($server)->not->toBeNull()
        ->and($child)->not->toBeNull();

    expect($server['kind'])->toBe('SERVER')
        ->and($server['name'])->toBe('vaulter.http.server')
        ->and($server['trace_id'])->toBe($traceId)
        ->and($server['parent_span_id'])->toBe($parentId)
        ->and($server['status'])->toBe('UNSET')
        ->and($child['kind'])->toBe('INTERNAL')
        ->and($child['trace_id'])->toBe($traceId)
        ->and($child['parent_span_id'])->toBe($server['span_id'])
        ->and($child['status'])->toBe('UNSET')
        ->and($child['attributes'])->toBe([
            'vaulter.layer' => 'action',
            'vaulter.outcome' => 'success',
            'vaulter.target' => 'webhook.pipeline',
        ]);
});

it('starts a new server root when traceparent is invalid', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);
    $request = Request::create('/api/vaulter/nodes', 'GET');
    $request->headers->set('traceparent', 'not-a-traceparent');

    $recorder->traceServer(request: $request, next: fn (): string => 'ok');
    $recorder->forceFlush();

    $rows = exportedRows($exporter);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['parent_span_id'])->toBeNull()
        ->and($rows[0]['trace_id'])->not->toBe('not-a-traceparent');
});

it('does not promote a remote unsampled parent', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);
    $request = Request::create('/api/vaulter/nodes', 'GET');
    $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');

    $result = $recorder->traceServer(
        request: $request,
        next: fn () => $recorder->span(
            name: 'vaulter.action.upload.sweep',
            operation: fn (): string => 'swept',
            attributes: [
                'vaulter.target' => 'upload.sweep',
                'vaulter.layer' => 'action',
            ],
        ),
    );
    $recorder->forceFlush();

    expect($result)->toBe('swept')
        ->and(exportedRows($exporter))->toBe([]);
});

it('marks uncaught exceptions as error without recording the exception body', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);

    expect(fn () => $recorder->span(
        name: 'vaulter.job.webhook.delivery',
        operation: static function (): void {
            throw new RuntimeException('delivery secret stack');
        },
        attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'job',
        ],
    ))->toThrow(RuntimeException::class, 'delivery secret stack');

    $recorder->forceFlush();
    $rows = exportedRows($exporter);
    $encoded = json_encode($rows, JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('ERROR')
        ->and($rows[0]['attributes']['vaulter.outcome'])->toBe('internal_error')
        ->and($encoded)->not->toContain('delivery secret stack')
        ->and($encoded)->not->toContain('RuntimeException');
});

it('keeps expected conflict outcomes unset', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);

    $result = $recorder->span(
        name: 'vaulter.action.upload.sweep',
        operation: fn (): string => 'conflict-kept',
        attributes: [
            'vaulter.target' => 'upload.sweep',
            'vaulter.layer' => 'command',
            'vaulter.outcome' => 'conflict',
            'node_id' => 'raw-identifier',
            'vaulter.correlation_slot' => 'slot-007',
        ],
    );
    $recorder->forceFlush();
    $rows = exportedRows($exporter);

    expect($result)->toBe('conflict-kept')
        ->and($rows[0]['status'])->toBe('UNSET')
        ->and($rows[0]['attributes'])->toBe([
            'vaulter.correlation_slot' => 'slot-007',
            'vaulter.layer' => 'command',
            'vaulter.outcome' => 'conflict',
            'vaulter.target' => 'upload.sweep',
        ]);
});

it('exports nothing when sampling is disabled and everything when the ratio is 1', function (): void {
    [$disabled, $disabledExporter] = tracedRecorder(ratio: 0.0);
    [$always, $alwaysExporter] = tracedRecorder(ratio: 1.0);

    foreach (range(1, 8) as $ignored) {
        $disabled->span(name: 'vaulter.action.webhook.retry', operation: fn (): int => 1, attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'action',
        ]);
        $always->span(name: 'vaulter.action.webhook.retry', operation: fn (): int => 1, attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'action',
        ]);
    }

    $disabled->forceFlush();
    $always->forceFlush();

    expect(exportedRows($disabledExporter))->toBe([])
        ->and(exportedRows($alwaysExporter))->toHaveCount(8);
});

it('exports a bounded fraction at the default one percent ratio', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 0.01);
    $iterations = 1000;

    foreach (range(1, $iterations) as $ignored) {
        $recorder->span(
            name: 'vaulter.action.webhook.relay',
            operation: static fn (): int => 1,
            attributes: [
                'vaulter.target' => 'webhook.pipeline',
                'vaulter.layer' => 'action',
            ],
        );
    }

    $recorder->forceFlush();
    $exported = count(exportedRows($exporter));

    expect($exported)->toBeGreaterThan(0)
        ->and($exported)->toBeLessThan((int) floor($iterations * 0.1));
});

it('leaves the business result unchanged when export fails', function (): void {
    Log::spy();
    $exporter = new class implements SpanExporterInterface
    {
        use SpanExporterTrait;

        protected function doExport(iterable $spans): bool
        {
            return false;
        }
    };
    $recorder = OpenTelemetryTraceRecorder::create(exporter: $exporter, ratio: 1.0);

    $result = $recorder->span(
        name: 'vaulter.action.upload.sweep',
        operation: fn (): string => 'still-swept',
        attributes: [
            'vaulter.target' => 'upload.sweep',
            'vaulter.layer' => 'action',
        ],
    );
    $recorder->forceFlush();

    expect($result)->toBe('still-swept');
});

it('resolves the package null recorder when host tracing is disabled', function (): void {
    expect(app(TraceRecorder::class))->toBeInstanceOf(NullTraceRecorder::class)
        ->and(config('vaulter.observability.tracing.enabled'))->toBeFalse()
        ->and(config('vaulter.routes.middleware.authenticated'))->not->toContain(OpenTelemetryTraceRecorder::class);
});

it('installs the host adapter when tracing is enabled and the sdk is present', function (): void {
    putenv('VAULTER_TRACING_ENABLED=true');
    $_ENV['VAULTER_TRACING_ENABLED'] = 'true';

    try {
        $this->refreshApplication();

        expect(config('vaulter.observability.tracing.enabled'))->toBeTrue()
            ->and(config('vaulter.observability.tracing.recorder'))->toBe(OpenTelemetryTraceRecorder::class)
            ->and(config('load.observability.tracing.ratio'))->toBe(0.01)
            ->and(app(TraceRecorder::class))->toBeInstanceOf(OpenTelemetryTraceRecorder::class)
            ->and(app(OpenTelemetryTraceRecorder::class)->handle(
                Request::create('/api/vaulter/nodes', 'GET'),
                static fn (): Response => new Response('ok'),
            )->getContent())->toBe('ok');
    } finally {
        putenv('VAULTER_TRACING_ENABLED');
        unset($_ENV['VAULTER_TRACING_ENABLED']);
        $this->refreshApplication();
    }
});

it('writes jsonl without product identifiers', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vlt-trace-');
    expect($path)->not->toBeFalse();
    $recorder = OpenTelemetryTraceRecorder::create(exportPath: $path, ratio: 1.0);

    $recorder->span(
        name: 'vaulter.job.webhook.delivery',
        operation: static fn (): string => 'delivered',
        attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'job',
            'upload_id' => 'should-not-export',
        ],
    );
    $recorder->forceFlush();
    $recorder->shutdown();

    $line = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    @unlink($path);

    expect($line['name'])->toBe('vaulter.job.webhook.delivery')
        ->and($line['attributes'])->not->toHaveKey('upload_id')
        ->and(json_encode($line, JSON_THROW_ON_ERROR))->not->toContain('should-not-export');
});

it('maps http 500 to server span error status', function (): void {
    [$recorder, $exporter] = tracedRecorder(ratio: 1.0);
    $request = Request::create('/api/vaulter/nodes', 'GET');

    $recorder->traceServer(
        request: $request,
        next: static fn (): Response => new Response('', 500),
    );
    $recorder->forceFlush();

    expect(exportedRows($exporter)[0]['status'])->toBe('ERROR');
});
