<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * A share token is a bearer secret in the URL: no public share response may be stored
 * or leak the URL through a Referer, and the token is never part of a metric label.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.features.share_links', true);
});

it('sends private, no-store and no-referrer on every public answer, the throttle included', function (): void {
    config()->set('vaulter.routes.rate_limits.public', 3);
    $file = V1::file(V1::drive(), 'h.txt', 'header bytes');
    ShareTestHelpers::share(DriveTestHelpers::model($file), token: 'headers-v1');

    $responses = [
        $this->getJson(V1::url('shares/headers-v1'))->assertOk(),
        $this->get(V1::url('shares/headers-v1/download'))->assertOk(),
        $this->getJson(V1::url('shares/wrong-token'))->assertNotFound(),
        $this->getJson(V1::url('shares/headers-v1'))->assertStatus(429),
    ];

    foreach ($responses as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('X-Vaulter-Api-Version'))->toBe('1')
            ->and($response->headers->get('X-Request-Id'))->toBeString();
    }
    expect($responses[1]->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($responses[1]->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($responses[1]->headers->get('Content-Disposition'))->toContain('h.txt');
});

it('labels public share metrics by route name, never by the token', function (): void {
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
    $file = V1::file(V1::drive(), 'm.txt', 'metric bytes');
    ShareTestHelpers::share(DriveTestHelpers::model($file), token: 'metric-secret-token');

    $this->get(V1::url('shares/metric-secret-token/download'))->assertOk();

    $emissions = app(MetricsRecorder::class)->emissions();
    $durations = array_values(array_filter($emissions, static fn (array $emission): bool => $emission['name'] === 'vaulter_http_request_duration_seconds'));

    expect($durations)->toHaveCount(1)
        ->and($durations[0]['labels']['operation'])->toBe('download')
        ->and(json_encode($emissions))->not->toContain('metric-secret-token');
});
