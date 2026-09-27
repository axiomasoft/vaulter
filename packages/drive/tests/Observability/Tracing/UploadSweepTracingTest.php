<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Observability\TracingAttributes;
use Vaulter\Observability\TracingSpanNames;
use Vaulter\Testing\FakeTraceRecorder;

beforeEach(function (): void {
    config()->set('vaulter.observability.tracing.enabled', true);
    config()->set('vaulter.observability.tracing.recorder', FakeTraceRecorder::class);
    $this->app->forgetInstance(TraceRecorder::class);
});

it('records command root and action child spans for upload.sweep', function (): void {
    Artisan::call('vaulter:sweep-uploads');

    $tracer = app(TraceRecorder::class);
    expect($tracer)->toBeInstanceOf(FakeTraceRecorder::class);

    $commandSpan = collect($tracer->spans())->first(
        fn (array $span): bool => $span['name'] === TracingSpanNames::UPLOAD_SWEEP
            && $span['attributes'] === TracingAttributes::forTarget(targetId: 'upload.sweep', layer: 'command'),
    );
    expect($commandSpan)->not->toBeNull();

    $tracer->assertSpan(
        name: TracingSpanNames::UPLOAD_SWEEP,
        attributes: TracingAttributes::forTarget(targetId: 'upload.sweep', layer: 'action'),
        parent: TracingSpanNames::UPLOAD_SWEEP,
    );
});
