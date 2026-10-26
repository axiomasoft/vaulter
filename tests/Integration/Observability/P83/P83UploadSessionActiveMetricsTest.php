<?php

declare(strict_types=1);

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Load\Observability\LoadStateMetrics;
use Vaulter\Testing\FakeMetricsRecorder;

beforeEach(function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records vaulter_upload_session_active gauges by protocol', function (): void {
    app(LoadStateMetrics::class)->observeUploadSessions();

    app(MetricsRecorder::class)->assertGauge(
        name: 'vaulter_upload_session_active',
        labels: ['protocol' => 'tus'],
    );
});
