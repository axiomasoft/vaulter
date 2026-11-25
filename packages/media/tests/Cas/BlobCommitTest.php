<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Support\Identity;

/**
 * The content-addressed commit flow: stage → hash the staged file →
 * find-or-create by the per-scope dedup key → promote bytes only on a newly
 * created row. Server-recompute beats any client claim; dedup yields ONE row and
 * ONE physical object for identical bytes.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('commits a blob: one row, an object on disk, status committed', function (): void {
    $store = app(BlobStore::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('hello cas'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($blob->status)->toBe(BlobStatus::Committed)
        ->and($blob->sha256)->toBe(hash('sha256', 'hello cas'))
        ->and($blob->size_bytes)->toBe(9)
        ->and(Blob::query()->count())->toBe(1);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('the server recomputes sha256 and ignores a lying client', function (): void {
    $store = app(BlobStore::class);

    // The client could send any sha256 they like; commit() never takes it — the
    // hash is recomputed from the staged temp file. We prove it by checking the
    // stored hash matches the REAL bytes, not a lie.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('real bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($blob->sha256)->toBe(hash('sha256', 'real bytes'))
        ->and($blob->sha256)->not->toBe(hash('sha256', 'a lie'));
});

it('dedup: the same bytes twice give ONE row, ONE object and two edges', function (): void {
    $store = app(BlobStore::class);

    $first = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $second = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($second->getKey())->toBe($first->getKey())
        ->and(Blob::query()->count())->toBe(1);

    // Two distinct versions reference the one blob — two edges, one physical object.
    $v1 = CasTestHelpers::reference('a.bin');
    $v2 = CasTestHelpers::reference('b.bin');
    CasTestHelpers::pin($first, $v1);
    CasTestHelpers::pin($second, $v2);

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    // ref_count is NOT bumped synchronously — it stays 0 until reconciliation.
    expect($first->fresh()->ref_count)->toBe(0);

    // Reconciliation rebuilds ref_count to the edge total (2).
    app(BlobReconciler::class)->reconcile();
    expect($first->fresh()->ref_count)->toBe(2);
});

it('a repeated commit is idempotent and writes no second object', function (): void {
    $store = app(BlobStore::class);

    $store->commit(CasTestHelpers::streamFor('idem'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $store->commit(CasTestHelpers::streamFor('idem'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));

    expect(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('answers a commit with an immutable snapshot of the committed row: its id, never a model', function (): void {
    $store = app(BlobStore::class);
    $scope = BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused');

    $first = $store->commit(CasTestHelpers::streamFor('snapshot bytes'), scope: $scope);
    $again = $store->commit(CasTestHelpers::streamFor('snapshot bytes'), scope: $scope);
    $row = Blob::query()->sole();

    expect($first)->toBeInstanceOf(BlobSnapshot::class)
        ->and($first->id)->toBe(Identity::stringify($row->getKey()))
        ->and($again->id)->toBe($first->id)
        ->and([$first->sha256, $first->sizeBytes, $first->scopeKey, $first->storageKey, $first->disk])->toBe([hash('sha256', 'snapshot bytes'), 14, $row->scope_key, $row->storage_key, $row->disk])
        ->and([$first->status, $first->intact, $first->chunked])->toBe([BlobStatus::Committed, true, false])
        ->and(fn (): BlobStatus => $first->status = BlobStatus::Deleted)->toThrow(Error::class);

    // The snapshot is a point in time: a later change of the row does not reach it, and read() follows the row by id.
    $row->forceFill(['status' => BlobStatus::Tombstoned])->save();
    expect($first->status)->toBe(BlobStatus::Committed)
        ->and(stream_get_contents($store->read($first)))->toBe('snapshot bytes')
        ->and(stream_get_contents($store->read($first->id)))->toBe('snapshot bytes');

    $row->forceDelete();
    expect($store->read($first))->toBeNull();
});
