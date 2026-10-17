<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Reconciliation (H.3): ref_count is a cache, rebuilt from the edge counts (the
 * liveness truth). It also flags dangling rows — a committed blob whose canonical
 * object is missing on disk.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('пересчитывает ref_count из числа рёбер', function (): void {
    $store = app(BlobStore::class);

    $blob = $store->commit(CasTestHelpers::streamFor('counted'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));

    $v1 = CasTestHelpers::reference('a');
    $v2 = CasTestHelpers::reference('b');
    CasTestHelpers::pin($blob, $v1);
    CasTestHelpers::pin($blob, $v2);

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

    $blob = $store->commit(CasTestHelpers::streamFor('orphaned'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // Stale cache pretends it is referenced 5 times.
    $blob->forceFill(['ref_count' => 5])->save();
    CasTestHelpers::unpin($blob, $version);

    app(BlobReconciler::class)->reconcile();

    expect($blob->fresh()->ref_count)->toBe(0);
});

it('помечает dangling-строку: committed-блоб без объекта на диске', function (): void {
    $store = app(BlobReconciler::class);

    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('vanished'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));

    // Simulate a botched delete: the row stays committed but the object is gone.
    Storage::disk('local')->delete($blob->storage_key);

    $report = $store->reconcile();

    expect($report['dangling'])->toContain((string) $blob->getKey());
});

it('читает строки блобов (берёт lock) раньше, чем считает пины', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('lock first'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    CasTestHelpers::pin($blob, CasTestHelpers::reference('a'));

    // sqlite takes no row lock, so the order of the two reads is what is checked here: the
    // locking read of the batch comes first, the pin count after it (as in the collector's mark).
    $connection = app(VaulterDatabase::class)->connection();
    $blobs = app(DatabaseConfig::class)->table('blobs');
    $pins = app(DatabaseConfig::class)->table('blob_references');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    app(BlobReconciler::class)->reconcile();

    $queries = array_column($connection->getQueryLog(), 'query');
    $connection->disableQueryLog();
    $rowsAt = array_find_key($queries, static fn (string $sql): bool => str_contains($sql, '"'.$blobs.'"') && str_starts_with($sql, 'select *') && str_contains($sql, '" in ('));
    $countAt = array_find_key($queries, static fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, '"'.$pins.'"'));

    expect($rowsAt)->not->toBeNull()
        ->and($countAt)->not->toBeNull()
        ->and($rowsAt)->toBeLessThan($countAt)
        ->and($blob->fresh()->ref_count)->toBe(1);
});
