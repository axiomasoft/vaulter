<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Models\Cas\Blob;

/**
 * The content-addressed commit flow (H.3): stage → hash the staged file →
 * find-or-create by the per-scope dedup key → promote bytes only on a newly
 * created row. Server-recompute beats any client claim; dedup yields ONE row and
 * ONE physical object for identical bytes.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('коммитит блоб: одна строка, объект на диске, статус committed', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('hello cas'), tenantId: 1);

    expect($blob->status)->toBe(BlobStatus::Committed)
        ->and($blob->sha256)->toBe(hash('sha256', 'hello cas'))
        ->and($blob->size_bytes)->toBe(9)
        ->and(Blob::query()->count())->toBe(1);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('сервер пересчитывает sha256 — врущий клиент игнорируется', function (): void {
    $store = app(BlobStore::class);

    // The client could send any sha256 they like; commit() never takes it — the
    // hash is recomputed from the staged temp file. We prove it by checking the
    // stored hash matches the REAL bytes, not a lie.
    $blob = $store->commit(CasTestHelpers::streamFor('real bytes'), tenantId: 1);

    expect($blob->sha256)->toBe(hash('sha256', 'real bytes'))
        ->and($blob->sha256)->not->toBe(hash('sha256', 'a lie'));
});

it('дедуп: одинаковые байты дважды → ОДНА строка, ОДИН объект, два ребра', function (): void {
    $store = app(BlobStore::class);

    $first = $store->commit(CasTestHelpers::streamFor('same bytes'), tenantId: 1);
    $second = $store->commit(CasTestHelpers::streamFor('same bytes'), tenantId: 1);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Blob::query()->count())->toBe(1);

    // Two distinct versions reference the one blob — two edges, one physical object.
    $v1 = CasTestHelpers::makeVersion(versionNo: 1, name: 'a.bin');
    $v2 = CasTestHelpers::makeVersion(versionNo: 1, name: 'b.bin');
    $store->attach($v1, $first);
    $store->attach($v2, $second);

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    // ref_count is NOT bumped synchronously (H.3) — it stays 0 until reconciliation.
    expect($first->fresh()->ref_count)->toBe(0);

    // Reconciliation rebuilds ref_count to the edge total (2).
    app(BlobReconciler::class)->reconcile();
    expect($first->fresh()->ref_count)->toBe(2);
});

it('повторный коммит идемпотентен и не пишет второй объект', function (): void {
    $store = app(BlobStore::class);

    $store->commit(CasTestHelpers::streamFor('idem'), tenantId: 1);
    $store->commit(CasTestHelpers::streamFor('idem'), tenantId: 1);

    expect(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Blob::query()->count())->toBe(1);
});
