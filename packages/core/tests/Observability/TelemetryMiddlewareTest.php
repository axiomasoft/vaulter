<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Modules\ModuleId;
use Vaulter\Observability\TelemetryMiddleware;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Testing\FakeTraceRecorder;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->metrics = new FakeMetricsRecorder;
    $this->tracer = new FakeTraceRecorder;
    app()->instance(MetricsRecorder::class, $this->metrics);
    app()->instance(TraceRecorder::class, $this->tracer);
    $this->context = OperationContext::for(Actor::user(AccessTestHelpers::user(4242)), ScopeRef::drive(17), TenantRef::id('acme-tenant'), 'corr-telemetry')
        ->withCausation('cause-telemetry');
});

function telemetrySpec(): OperationSpec
{
    return new OperationSpec(name: 'drive.folders.create', module: new ModuleId('drive'));
}

it('wraps each operation in one span with the context attributes and no personal data', function (): void {
    app(OperationRunner::class)->write(telemetrySpec(), $this->context, static fn (): string => 'done');

    $spans = array_values(array_filter($this->tracer->spans(), static fn (array $span): bool => $span['name'] === TelemetryMiddleware::SPAN));
    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes'])->toBe([
            'vaulter.operation' => 'drive.folders.create',
            'vaulter.module' => 'drive',
            'vaulter.operation.nested' => false,
            'vaulter.correlation_id' => 'corr-telemetry',
            'vaulter.scope.kind' => 'drive',
            'vaulter.tenant' => 'id',
            'vaulter.actor.kind' => 'user',
            'vaulter.causation_id' => 'cause-telemetry',
        ]);
    $flat = implode('|', array_map(strval(...), $spans[0]['attributes']));
    expect($flat)->not->toContain('4242')->not->toContain('acme-tenant');
});

it('counts and times each operation by outcome', function (Closure $attempt, string $outcome): void {
    try {
        app(OperationRunner::class)->write(telemetrySpec(), $this->context, $attempt);
    } catch (Throwable) {
        // The outcome is what is measured here.
    }

    $this->metrics->assertCounter(TelemetryMiddleware::TOTAL, ['operation' => 'drive.folders.create', 'outcome' => $outcome]);
    $this->metrics->assertHistogram(TelemetryMiddleware::DURATION, ['operation' => 'drive.folders.create', 'outcome' => $outcome]);
})->with([
    'ok' => [static fn (): string => 'done', 'ok'],
    'rejected' => [static fn () => throw PermissionDeniedException::forAction('test'), 'rejected'],
    'error' => [static fn () => throw new RuntimeException('boom'), 'error'],
]);

it('never puts the idempotency key into telemetry', function (): void {
    $keyed = $this->context->withIdempotencyKey('very-secret-key');

    expect(fn () => app(OperationRunner::class)->write(telemetrySpec(), $keyed, static fn (): string => 'done'))->toThrow(InvalidArgumentException::class);

    foreach ($this->tracer->spans() as $span) {
        expect(implode('|', array_map(strval(...), $span['attributes'])))->not->toContain('very-secret-key');
    }
});
