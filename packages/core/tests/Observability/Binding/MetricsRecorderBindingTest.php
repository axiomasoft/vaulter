<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Testing\FakeMetricsRecorder;

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', false);
    config()->set('vaulter.observability.recorder', null);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('resolves NullMetricsRecorder by default', function (): void {
    expect(app(MetricsRecorder::class))->toBeInstanceOf(NullMetricsRecorder::class);
});

it('keeps Null when observability is disabled even if a recorder class is configured', function (): void {
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);

    expect(app(MetricsRecorder::class))->toBeInstanceOf(NullMetricsRecorder::class);
});

it('falls back to Null for a missing or invalid recorder class when enabled', function (): void {
    Log::spy();

    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', 'Definitely\\Missing\\Recorder');

    expect(app(MetricsRecorder::class))->toBeInstanceOf(NullMetricsRecorder::class);
    Log::shouldHaveReceived('warning');
});

it('falls back to Null when the configured class does not implement the contract', function (): void {
    Log::spy();

    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', stdClass::class);

    expect(app(MetricsRecorder::class))->toBeInstanceOf(NullMetricsRecorder::class);
    Log::shouldHaveReceived('warning');
});

it('resolves a valid host adapter when observability is enabled', function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);

    $recorder = app(MetricsRecorder::class);

    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
});

it('NullMetricsRecorder executes timing callbacks without recording emissions', function (): void {
    $fake = new FakeMetricsRecorder;
    $null = new NullMetricsRecorder;

    $null->counter(name: 'vaulter_http_request_duration_seconds', amount: 1, labels: ['operation' => 'list']);
    $null->gauge(name: 'vaulter_collab_room_active', value: 3);
    $null->histogram(name: 'vaulter_tree_query_duration_seconds', value: 0.5, labels: ['query_kind' => 'children']);

    expect($null->timing(name: 'vaulter_export_duration_seconds', operation: fn (): int => 42, labels: ['format' => 'html']))
        ->toBe(42);

    expect($fake->emissions())->toBe([]);
});

it('rethrows the same exception from NullMetricsRecorder timing', function (): void {
    $null = new NullMetricsRecorder;
    $exception = new RuntimeException('boom');

    $null->timing(name: 'vaulter_export_duration_seconds', operation: function () use ($exception): void {
        throw $exception;
    });
})->throws(RuntimeException::class, 'boom');

it('records normalized emissions and supports assert helpers on FakeMetricsRecorder', function (): void {
    $fake = new FakeMetricsRecorder;

    $fake->counter(name: 'vaulter_activity_write_total', amount: 2, labels: ['verb' => 'created']);
    $fake->gauge(name: 'vaulter_collab_room_active', value: 4);
    $fake->histogram(name: 'vaulter_tree_query_duration_seconds', value: 0.25, labels: ['tier' => 'S', 'query_kind' => 'search']);
    $fake->timing(name: 'vaulter_export_duration_seconds', operation: fn (): string => 'ok', labels: ['format' => 'md', 'tier' => 'S']);

    expect($fake->emissions())->toHaveCount(4);
    $fake->assertCounter(name: 'vaulter_activity_write_total', labels: ['verb' => 'created']);
    $fake->assertGauge(name: 'vaulter_collab_room_active');
    $fake->assertHistogram(name: 'vaulter_tree_query_duration_seconds', labels: ['query_kind' => 'search', 'tier' => 'S']);
    $fake->assertHistogram(name: 'vaulter_export_duration_seconds');
});

it('normalizes bool labels to true and false strings', function (): void {
    $fake = new FakeMetricsRecorder;

    $fake->gauge(name: 'vaulter_activity_feed_duration_seconds', value: 1, labels: ['filtered' => true, 'tier' => 'M']);

    expect($fake->emissions()[0]['labels'])->toBe(['filtered' => 'true', 'tier' => 'M']);
});
