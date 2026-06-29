<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\BlobStore;
use Vaulter\Enums\BlobStatus;
use Vaulter\Tests\Cas\CasTestHelpers;

/**
 * The CAS console commands wire the collector / reconciler to artisan (H.3): the
 * GC tombstones-then-sweeps and reconcile rebuilds ref_count and reports.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
    config()->set('vaulter.gc.grace_hours', 1);
});

it('vaulter:gc-blobs прогоняет обе фазы', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('via command'), tenantId: 1);
    // Already tombstoned past grace → the sweep phase removes it.
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subDay()])->save();

    $this->artisan('vaulter:gc-blobs')
        ->assertSuccessful();

    expect($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});

it('vaulter:gc-blobs --pending подметает упавший staging', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('half upload'), tenantId: 1);
    // A crashed staging: pending, old, no edge.
    $blob->forceFill(['status' => BlobStatus::Pending, 'first_seen_at' => now()->subDay()])->save();

    $this->artisan('vaulter:gc-blobs', ['--pending' => true])
        ->assertSuccessful();

    expect($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});

it('vaulter:reconcile-blobs корректирует ref_count и сообщает', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('reconcile cmd'), tenantId: 1);
    $version = CasTestHelpers::makeVersion();
    $store->attach($version, $blob);

    $this->artisan('vaulter:reconcile-blobs')
        ->assertSuccessful();

    expect($blob->fresh()->ref_count)->toBe(1);
});

it('vaulter:reconcile-blobs падает, когда есть dangling-строка', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling cmd'), tenantId: 1);

    Storage::disk('local')->delete($blob->storage_key);

    $this->artisan('vaulter:reconcile-blobs')
        ->assertFailed();
});
