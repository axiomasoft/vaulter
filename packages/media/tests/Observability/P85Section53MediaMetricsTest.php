<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    config()->set('vaulter.observability.enabled', true);
    config()->set('vaulter.observability.recorder', FakeMetricsRecorder::class);
    $this->app->forgetInstance(MetricsRecorder::class);
});

it('records gc mark and sweep metrics', function (): void {
    $store = app(BlobStore::class);
    $blob = $store->commit(CasTestHelpers::streamFor('gc-metrics'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $version = CasTestHelpers::makeVersion();
    $store->attach($version, $blob);
    $store->detach($version, $blob);
    app(BlobReconciler::class)->reconcile();

    app(BlobGarbageCollector::class)->collect(graceHours: 1);

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_gc_duration_seconds', labels: ['phase' => 'mark']);
    $recorder->assertHistogram(name: 'vaulter_gc_duration_seconds', labels: ['phase' => 'sweep']);
    $recorder->assertHistogram(name: 'vaulter_gc_batch_rows');
    $recorder->assertCounter(name: 'vaulter_gc_rows_scanned_total');
    $recorder->assertCounter(name: 'vaulter_gc_objects_deleted_total');
});

it('records reconcile metrics when ref counts are rebuilt', function (): void {
    $store = app(BlobStore::class);
    $blob = $store->commit(CasTestHelpers::streamFor('reconcile-metrics'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $blob->forceFill(['ref_count' => 99])->save();

    app(BlobReconciler::class)->reconcile();

    $recorder = app(MetricsRecorder::class);
    $recorder->assertHistogram(name: 'vaulter_reconcile_duration_seconds');
    $recorder->assertCounter(name: 'vaulter_reconcile_objects_total');
});

it('records gc metrics via vaulter:blobs:gc command', function (): void {
    Artisan::call('vaulter:blobs:gc');

    app(MetricsRecorder::class)->assertHistogram(name: 'vaulter_gc_duration_seconds', labels: ['phase' => 'mark']);
});

it('records reconcile metrics via vaulter:blobs:reconcile command', function (): void {
    Artisan::call('vaulter:blobs:reconcile');

    app(MetricsRecorder::class)->assertHistogram(name: 'vaulter_reconcile_duration_seconds');
});
