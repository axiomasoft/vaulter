<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Two-phase, race-safe GC: mark unreferenced committed blobs as tombstoned
 * (grace deadline), then sweep tombstoned blobs past grace to a physical delete.
 * A referenced blob is never touched; a concurrent re-commit resurrects a
 * tombstone before the sweep can delete it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('phase 1 marks an unreferenced committed blob tombstoned with a grace deadline', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('garbage'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    // The mark scan keys on committed ∧ ref_count(cache)<=0 — exactly what
    // reconciliation leaves on a blob whose last edge was detached. No grace is
    // pre-armed; tombstone() sets delete_after_at when it marks.
    $blob->forceFill(['ref_count' => 0])->save();

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($blob->fresh()->delete_after_at)->not->toBeNull();
});

it('phase 1 does NOT touch a blob with a live edge (liveness is the edge, not ref_count)', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('referenced'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // ref_count cache is stale (0) but the EDGE exists — the blob enters the scan
    // on ref_count<=0, and GC must re-verify under the lock and skip.
    $blob->forceFill(['ref_count' => 0])->save();

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});

it('phase 2: sweep deletes bytes only of tombstoned blobs past grace and sets status deleted', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('sweep me'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $key = $blob->storage_key;
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->subMinute(),
    ])->save();

    Storage::disk('local')->assertExists($key);

    $result = $gc->sweep();

    expect($result['swept'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($blob->fresh()->delete_after_at)->toBeNull();

    Storage::disk('local')->assertMissing($key);
});

it('phase 2 does NOT delete tombstoned blobs inside the grace window', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('still in grace'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->addHour(),
    ])->save();

    $result = $gc->sweep();

    expect($result['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('resurrection: a commit onto a tombstoned blob untombstones it (status committed)', function (): void {
    $store = app(BlobStore::class);

    // A blob got tombstoned (GC phase 1) — bytes still on disk during grace.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('phoenix'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->addHour(),
    ])->save();

    // A concurrent upload of the SAME bytes arrives. It must un-tombstone, not
    // create a second row (this is what makes online GC safe).
    $resurrected = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('phoenix'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($resurrected->getKey())->toBe($blob->getKey())
        ->and($resurrected->status)->toBe(BlobStatus::Committed)
        ->and($resurrected->delete_after_at)->toBeNull()
        ->and(Blob::query()->count())->toBe(1);
});

it('resurrection before sweep saves the bytes: after it the sweep touches nothing', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    // Tombstoned past grace — a sweep candidate.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('saved'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->subMinute(),
    ])->save();

    // Ordering we simulate (SQLite single-writer): the commit WINS first and
    // resurrects the blob...
    CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('saved'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    // ...so when the sweep then runs, it re-reads under the lock, sees committed,
    // and refuses to delete — the bytes survive.
    $result = $gc->sweep();

    expect($result['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('idempotence: a repeated sweep over a deleted blob is a no-op', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('twice swept'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subMinute()])->save();

    $gc->sweep();
    $second = $gc->sweep();

    expect($second['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});
