<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The CAS console commands wire the collector / reconciler to artisan: the
 * GC tombstones-then-sweeps and reconcile rebuilds ref_count and reports.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    config()->set('vaulter-media.gc.grace_hours', 1);
});

it('vaulter:blobs:gc runs both phases', function (): void {
    $store = app(BlobStore::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('via command'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    // Already tombstoned past grace → the sweep phase removes it.
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subDay()])->save();

    $this->artisan('vaulter:blobs:gc')
        ->assertSuccessful();

    expect($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});

it('vaulter:blobs:gc --pending sweeps a failed staging', function (): void {
    $store = app(BlobStore::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('half upload'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    // A crashed staging: pending, older than the orphan grace (24h by default), no edge.
    $blob->forceFill(['status' => BlobStatus::Pending, 'first_seen_at' => now()->subDays(2)])->save();

    $this->artisan('vaulter:blobs:gc', ['--pending' => true])
        ->assertSuccessful();

    expect($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});

it('vaulter:blobs:reconcile corrects ref_count and reports it', function (): void {
    $store = app(BlobStore::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('reconcile cmd'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    $this->artisan('vaulter:blobs:reconcile')
        ->assertSuccessful();

    expect($blob->fresh()->ref_count)->toBe(1);
});

it('vaulter:blobs:reconcile fails when there is a dangling row', function (): void {
    $blob = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor('dangling cmd'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    Storage::disk('local')->delete($blob->storage_key);

    $this->artisan('vaulter:blobs:reconcile')
        ->assertFailed();
});
