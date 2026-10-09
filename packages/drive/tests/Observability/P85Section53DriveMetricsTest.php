<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records sweep upload metrics for stale sessions', function (): void {
    $storage = UploadTestHelpers::storage();
    $stale = UploadTestHelpers::session($storage, totalSizeBytes: 100);
    app(TusUploadHandler::class)->patch($stale->refresh(), UploadTestHelpers::streamFor('partial'), 0);
    $stale->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    Artisan::call('vaulter:sweep-uploads');

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_sweep_tick_seconds');
    $recorder->assertHistogram(name: 'vaulter_sweep_batch_rows');
    $recorder->assertCounter(name: 'vaulter_sweep_expired_total');
    $recorder->assertCounter(name: 'vaulter_sweep_skipped_total');
});

it('records purge trash metrics when a subtree is purged', function (): void {
    config()->set('vaulter-drive.trash.retention_days', 30);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $node->getKey()])->save();

    Artisan::call('vaulter:purge-trash');

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_purge_duration_seconds');
    $recorder->assertHistogram(name: 'vaulter_purge_batch_nodes');
    $recorder->assertCounter(name: 'vaulter_purge_nodes_total');
});
