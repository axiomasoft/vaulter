<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobReconciler;
use Vaulter\Contracts\BlobStore;
use Vaulter\Tests\Cas\CasTestHelpers;

/**
 * Reconciliation (H.3): ref_count is a cache, rebuilt from the edge counts (the
 * liveness truth). It also flags dangling rows — a committed blob whose canonical
 * object is missing on disk.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('пересчитывает ref_count из числа рёбер', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('counted'), tenantId: 1);

    $v1 = CasTestHelpers::makeVersion(versionNo: 1, name: 'a');
    $v2 = CasTestHelpers::makeVersion(versionNo: 1, name: 'b');
    $store->attach($v1, $blob);
    $store->attach($v2, $blob);

    // Commit never bumps ref_count (H.3); the cache is stale at 0.
    expect($blob->fresh()->ref_count)->toBe(0);

    $report = app(BlobReconciler::class)->reconcile();

    expect($blob->fresh()->ref_count)->toBe(2)
        ->and($report['reconciled'])->toBe(1)
        ->and($report['changed'])->toBe(1)
        ->and($report['dangling'])->toBe([]);
});

it('сбрасывает ref_count к 0, когда все рёбра удалены', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('orphaned'), tenantId: 1);
    $version = CasTestHelpers::makeVersion();
    $store->attach($version, $blob);

    // Stale cache pretends it is referenced 5 times.
    $blob->forceFill(['ref_count' => 5])->save();
    $store->detach($version, $blob);

    app(BlobReconciler::class)->reconcile();

    expect($blob->fresh()->ref_count)->toBe(0);
});

it('помечает dangling-строку: committed-блоб без объекта на диске', function (): void {
    $store = app(BlobReconciler::class);

    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('vanished'), tenantId: 1);

    // Simulate a botched delete: the row stays committed but the object is gone.
    Storage::disk('local')->delete($blob->storage_key);

    $report = $store->reconcile();

    expect($report['dangling'])->toContain((string) $blob->getKey());
});
