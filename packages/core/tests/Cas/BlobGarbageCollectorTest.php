<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobGarbageCollector;
use Vaulter\Contracts\BlobStore;
use Vaulter\Enums\BlobStatus;
use Vaulter\Models\Blob;
use Vaulter\Tests\Cas\CasTestHelpers;

/**
 * Two-phase, race-safe GC (H.3): mark unreferenced committed blobs as tombstoned
 * (grace deadline), then sweep tombstoned blobs past grace to a physical delete.
 * A referenced blob is never touched; a concurrent re-commit resurrects a
 * tombstone before the sweep can delete it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('фаза 1: помечает unreferenced committed-блоб tombstoned с grace-дедлайном', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = $store->commit(CasTestHelpers::streamFor('garbage'), tenantId: 1);
    // The mark scan keys on committed ∧ ref_count(cache)<=0 — exactly what
    // reconciliation leaves on a blob whose last edge was detached. No grace is
    // pre-armed; tombstone() sets delete_after_at when it marks.
    $blob->forceFill(['ref_count' => 0])->save();

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($blob->fresh()->delete_after_at)->not->toBeNull();
});

it('фаза 1: НЕ трогает блоб с живым ребром (liveness = ребро, не ref_count)', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = $store->commit(CasTestHelpers::streamFor('referenced'), tenantId: 1);
    $version = CasTestHelpers::makeVersion();
    $store->attach($version, $blob);

    // ref_count cache is stale (0) but the EDGE exists — the blob enters the scan
    // on ref_count<=0, and GC must re-verify under the lock and skip.
    $blob->forceFill(['ref_count' => 0])->save();

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});

it('фаза 2: sweep удаляет байты только у tombstoned past-grace, статус deleted', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = $store->commit(CasTestHelpers::streamFor('sweep me'), tenantId: 1);
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

it('фаза 2: НЕ удаляет tombstoned внутри grace-окна', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = $store->commit(CasTestHelpers::streamFor('still in grace'), tenantId: 1);
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->addHour(),
    ])->save();

    $result = $gc->sweep();

    expect($result['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('воскрешение: коммит на tombstoned-блоб разтомбствляет его (status→committed)', function (): void {
    $store = app(BlobStore::class);

    // A blob got tombstoned (GC phase 1) — bytes still on disk during grace.
    $blob = $store->commit(CasTestHelpers::streamFor('phoenix'), tenantId: 1);
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->addHour(),
    ])->save();

    // A concurrent upload of the SAME bytes arrives. It must un-tombstone, not
    // create a second row (this is what makes online GC safe — H.3).
    $resurrected = $store->commit(CasTestHelpers::streamFor('phoenix'), tenantId: 1);

    expect($resurrected->getKey())->toBe($blob->getKey())
        ->and($resurrected->status)->toBe(BlobStatus::Committed)
        ->and($resurrected->delete_after_at)->toBeNull()
        ->and(Blob::query()->count())->toBe(1);
});

it('воскрешение перед sweep спасает байты: после resurrection sweep ничего не трогает', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    // Tombstoned past grace — a sweep candidate.
    $blob = $store->commit(CasTestHelpers::streamFor('saved'), tenantId: 1);
    $blob->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->subMinute(),
    ])->save();

    // Ordering we simulate (SQLite single-writer): the commit WINS first and
    // resurrects the blob...
    $store->commit(CasTestHelpers::streamFor('saved'), tenantId: 1);

    // ...so when the sweep then runs, it re-reads under the lock, sees committed,
    // and refuses to delete — the bytes survive.
    $result = $gc->sweep();

    expect($result['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('идемпотентность: повторный sweep по deleted-блобу — no-op', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = $store->commit(CasTestHelpers::streamFor('twice swept'), tenantId: 1);
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subMinute()])->save();

    $gc->sweep();
    $second = $gc->sweep();

    expect($second['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted);
});
