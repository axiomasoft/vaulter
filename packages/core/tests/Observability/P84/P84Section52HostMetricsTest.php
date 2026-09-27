<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Access\Permission;
use Vaulter\Load\Http\Middleware\RecordRequestPeakMemory;
use Vaulter\Load\Listeners\RecordThrottledResponse;
use Vaulter\Load\Observability\DownloadStreamMetrics;
use Vaulter\Support\LoadTier;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Sharing\ShareTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
    LoadTier::set('M');
});

/**
 * @param array<string, string>|null $labels
 */
function p84LastValue(FakeMetricsRecorder $recorder, string $type, string $name, ?array $labels = null): int|float
{
    foreach (array_reverse($recorder->emissions()) as $emission) {
        if ($emission['type'] !== $type || $emission['name'] !== $name) {
            continue;
        }

        if ($labels !== null) {
            ksort($labels);

            if ($emission['labels'] !== $labels) {
                continue;
            }
        }

        return $emission['value'];
    }

    throw new RuntimeException("Missing {$type} emission for {$name}");
}

it('records vaulter_request_peak_memory_bytes on mapped download operations in terminate', function (): void {
    $middleware = app(RecordRequestPeakMemory::class);
    $request = Request::create('/api/vaulter/nodes/1/download', 'GET');
    $request->attributes->set(LoadTier::REQUEST_ATTRIBUTE, 'M');

    $response = $middleware->handle($request, static fn (): Response => new Response('', 200));
    $middleware->terminate($request, $response);

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertGauge(
        name: 'vaulter_request_peak_memory_bytes',
        labels: ['operation' => 'download', 'tier' => 'M'],
    );
    expect(p84LastValue(
        $recorder,
        'gauge',
        'vaulter_request_peak_memory_bytes',
        ['operation' => 'download', 'tier' => 'M'],
    ))->toBeGreaterThan(0);
});

it('records vaulter_request_peak_memory_bytes on mapped upload operations in terminate', function (): void {
    $middleware = app(RecordRequestPeakMemory::class);
    $request = Request::create('/api/vaulter/storages/1/uploads', 'POST');
    $request->attributes->set(LoadTier::REQUEST_ATTRIBUTE, 'M');

    $response = $middleware->handle($request, static fn (): Response => new Response('', 201));
    $middleware->terminate($request, $response);

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertGauge(
        name: 'vaulter_request_peak_memory_bytes',
        labels: ['operation' => 'single_shot', 'tier' => 'M'],
    );
    expect(p84LastValue(
        $recorder,
        'gauge',
        'vaulter_request_peak_memory_bytes',
        ['operation' => 'single_shot', 'tier' => 'M'],
    ))->toBeGreaterThan(0);
});

it('records vaulter_throttled_total for throttled share downloads', function (): void {
    $request = Request::create('/api/vaulter/share/token/download', 'GET');
    $response = new Response('', 429);

    app(RecordThrottledResponse::class)->handle(new RequestHandled($request, $response));

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertCounter(
        name: 'vaulter_throttled_total',
        labels: ['route' => 'share.download'],
    );
});

it('records vaulter_download_stream_active from php-fpm status snapshot', function (): void {
    Http::fake([
        '127.0.0.1:8081/fpm-status*' => Http::response([
            'max children' => 8,
            'processes' => [
                [
                    'state' => 'Running',
                    'request uri' => '/index.php/api/vaulter/nodes/42/download',
                ],
                [
                    'state' => 'Running',
                    'request uri' => '/index.php/api/vaulter/storages/1/nodes',
                ],
            ],
        ]),
    ]);

    app(DownloadStreamMetrics::class)->observeActiveStreams();

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertGauge(name: 'vaulter_download_stream_active');
    expect(p84LastValue($recorder, 'gauge', 'vaulter_download_stream_active'))->toBe(1);
});

it('caps vaulter_download_stream_active at php-fpm max children', function (): void {
    Http::fake([
        '127.0.0.1:8081/fpm-status*' => Http::response([
            'max children' => 1,
            'processes' => [
                [
                    'state' => 'Running',
                    'request uri' => '/index.php/api/vaulter/nodes/42/download',
                ],
                [
                    'state' => 'Running',
                    'request uri' => '/index.php/api/vaulter/share/tok/download',
                ],
            ],
        ]),
    ]);

    app(DownloadStreamMetrics::class)->observeActiveStreams();

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    expect(p84LastValue($recorder, 'gauge', 'vaulter_download_stream_active'))->toBe(1);
});

it('records download ttfb and bytes on public share download', function (): void {
    $drive = DriveTestHelpers::drive();
    $file = $drive->upload(DriveTestHelpers::streamFor('share-bytes'), originalName: 'shared.bin');
    ShareTestHelpers::share(
        $file->refresh(),
        token: 'metrics-share',
        permissions: Permission::Read->value,
    );

    $response = $this->get('api/vaulter/share/metrics-share/download');
    $response->assertOk();
    expect($response->streamedContent())->toBe('share-bytes');

    $recorder = app(MetricsRecorder::class);
    expect($recorder)->toBeInstanceOf(FakeMetricsRecorder::class);
    $recorder->assertHistogram(
        name: 'vaulter_download_ttfb_seconds',
        labels: ['tier' => 'M'],
    );
    $recorder->assertCounter(
        name: 'vaulter_download_bytes_total',
        labels: ['source' => 'share'],
    );
    expect(p84LastValue(
        $recorder,
        'counter',
        'vaulter_download_bytes_total',
        ['source' => 'share'],
    ))->toBe(strlen('share-bytes'));
});
