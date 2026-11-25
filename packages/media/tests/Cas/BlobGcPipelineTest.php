<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The end-to-end GC pipeline WITHOUT stamping delete_after_at by hand. The mark phase used
 * to scan by `delete_after_at < now`, which no commit or detach path ever sets on a
 * committed blob, so a really orphaned blob never entered the scan and its bytes leaked
 * forever. These tests run the real chain
 * commit → attach → detach → reconcile → gc and prove that an orphaned blob is
 * collected WITHOUT a single forceFill in the test.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('real pipeline: detach, reconcile and mark tombstone an orphaned committed blob (no manual stamping)', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    // Commit a blob and attach a real edge: the blob is live.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('orphan-me'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // Remove the only edge: the blob is orphaned, but the ref_count CACHE still lies.
    CasTestHelpers::unpin($blob, $version);

    // Reconciliation rebuilds the cache from the edge count, so ref_count = 0.
    $reconciler->reconcile();
    expect($blob->fresh()->ref_count)->toBe(0)
        ->and($blob->fresh()->delete_after_at)->toBeNull();

    // Mark must pick up committed ∧ ref_count<=0 and tombstone it with a grace deadline;
    // NO delete_after_at was set by hand before this point.
    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($blob->fresh()->delete_after_at)->not->toBeNull()
        ->and($blob->fresh()->delete_after_at->isFuture())->toBeTrue();
});

it('real pipeline: sweep leaves a fresh tombstone alone during grace and deletes the bytes after it', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('full-pipeline'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);
    CasTestHelpers::unpin($blob, $version);

    $reconciler->reconcile();

    // Mark with a small grace (but >= 1h after the floor): the bytes are still on disk, sweep waits.
    $gc->mark(graceHours: 1);
    $key = $blob->storage_key;
    Storage::disk('local')->assertExists($key);

    expect($gc->sweep()['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);
    Storage::disk('local')->assertExists($key);

    // Travel past the grace window: now the sweep may delete physically.
    $this->travel(2)->hours();

    $result = $gc->sweep();

    expect($result['swept'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($blob->fresh()->delete_after_at)->toBeNull();
    Storage::disk('local')->assertMissing($key);
});

it('real pipeline: mark skips a blob that is still referenced (liveness is the edge)', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('still-referenced'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // Reconciliation sets ref_count=1, so the blob never enters the candidate scan.
    $reconciler->reconcile();
    expect($blob->fresh()->ref_count)->toBe(1);

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});
