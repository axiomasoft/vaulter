<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Load\Listeners\FlushTraceExporter;
use Vaulter\Load\Observability\OpenTelemetryTraceRecorder;
use Vaulter\Load\Observability\RunScopedSpanExporter;
use Vaulter\Observability\NullTraceRecorder;

it('flushes on request, job and command terminal hooks and shuts down once', function (): void {
    $exporter = new InMemoryExporter;
    $recorder = OpenTelemetryTraceRecorder::create(exporter: $exporter, ratio: 1.0);
    $listener = new FlushTraceExporter(recorder: $recorder);

    $recorder->span(
        name: 'vaulter.action.webhook.relay',
        operation: static fn (): int => 1,
        attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'action',
        ],
    );

    expect($exporter->getSpans())->toBe([]);

    $listener->handle(new RequestHandled(Request::create('/'), new Response('ok')));
    expect($exporter->getSpans())->toHaveCount(1);

    $recorder->span(
        name: 'vaulter.job.webhook.delivery',
        operation: static fn (): int => 2,
        attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'job',
        ],
    );
    $listener->handle(new JobProcessed('sync', new stdClass));
    expect($exporter->getSpans())->toHaveCount(2);

    $recorder->span(
        name: 'vaulter.action.webhook.retry',
        operation: static fn (): int => 3,
        attributes: [
            'vaulter.target' => 'webhook.pipeline',
            'vaulter.layer' => 'action',
        ],
    );
    $listener->handle(new JobFailed('sync', new stdClass, new RuntimeException('hidden')));
    expect($exporter->getSpans())->toHaveCount(3);

    $recorder->span(
        name: 'vaulter.action.upload.sweep',
        operation: static fn (): int => 4,
        attributes: [
            'vaulter.target' => 'upload.sweep',
            'vaulter.layer' => 'action',
        ],
    );
    $listener->handle(new JobExceptionOccurred('sync', new stdClass, new RuntimeException('hidden')));

    $recorder->span(
        name: 'vaulter.action.upload.sweep',
        operation: static fn (): int => 5,
        attributes: [
            'vaulter.target' => 'upload.sweep',
            'vaulter.layer' => 'command',
        ],
    );
    $listener->handle(new CommandFinished(
        'vaulter:uploads:sweep',
        new ArrayInput([]),
        new BufferedOutput,
        0,
    ));

    $rows = array_map(
        static fn ($span): array => RunScopedSpanExporter::row($span),
        $exporter->getSpans(),
    );
    $encoded = json_encode($rows, JSON_THROW_ON_ERROR);

    $recorder->shutdown();
    $recorder->shutdown();

    expect($rows)->toHaveCount(5)
        ->and($recorder->shutdownCount())->toBe(1)
        ->and($encoded)->not->toContain('hidden');
});

it('does not flush when the package null recorder is bound', function (): void {
    $listener = new FlushTraceExporter(recorder: new NullTraceRecorder);

    expect(fn () => $listener->handle(new RequestHandled(Request::create('/'), new Response('ok'))))
        ->not->toThrow(Throwable::class);
});
