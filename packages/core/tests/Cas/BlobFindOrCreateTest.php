<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobLock;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;

/**
 * find-or-create regressions (H.3): the row id is always real (the ON CONFLICT
 * RETURNING null-id gotcha is avoided), the INSERT-race loser re-SELECTs and
 * reuses, and a tombstoned match is resurrected — all inside one locked
 * transaction.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('возвращает РЕАЛЬНЫЙ id (регрессия на null-id из ON CONFLICT DO NOTHING)', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'real id');

    $result = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha,
        sizeBytes: 7,
        storageKey: "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}",
        disk: 'local',
        dedupScope: 'tenant',
        tenantId: 1,
        storageId: null,
    ));

    expect($result['created'])->toBeTrue()
        ->and($result['blob']->getKey())->not->toBeNull()
        ->and($result['blob']->exists)->toBeTrue()
        ->and($result['blob']->status)->toBe(BlobStatus::Committed);
});

it('второй find-or-create тех же байт → created=false, тот же id', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'second hit');
    $key = "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}";

    $first = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 10, storageKey: $key, disk: 'local',
        dedupScope: 'tenant', tenantId: 1, storageId: null,
    ));

    $second = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 10, storageKey: $key, disk: 'local',
        dedupScope: 'tenant', tenantId: 1, storageId: null,
    ));

    expect($second['created'])->toBeFalse()
        ->and($second['blob']->getKey())->toBe($first['blob']->getKey())
        ->and(Blob::query()->count())->toBe(1);
});

it('find-or-create на tombstoned-строке воскрешает её', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'resurrect-me');
    $key = "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}";

    $created = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 5, storageKey: $key, disk: 'local',
        dedupScope: 'tenant', tenantId: 1, storageId: null,
    ));

    Blob::query()->whereKey($created['blob']->getKey())->update([
        'status' => BlobStatus::Tombstoned->value,
        'delete_after_at' => now()->addHour(),
    ]);

    $again = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 5, storageKey: $key, disk: 'local',
        dedupScope: 'tenant', tenantId: 1, storageId: null,
    ));

    expect($again['created'])->toBeFalse()
        ->and($again['blob']->status)->toBe(BlobStatus::Committed)
        ->and($again['blob']->delete_after_at)->toBeNull();
});

it('BlobLock на sqlite: advisory-lock — no-op, single-writer сериализует', function (): void {
    $lock = app(BlobLock::class);

    // SQLite test connection: no advisory lock, no FOR UPDATE — the unique
    // constraint + single-writer transaction is the backbone here.
    expect($lock->isPostgres())->toBeFalse()
        ->and($lock->usesRowLock())->toBeFalse();

    // acquire() is a safe no-op on sqlite (does not throw); we assert it returns
    // without error by reaching the post-transaction expectation.
    $reached = false;

    DB::transaction(function () use ($lock, &$reached): void {
        $lock->acquire(scope: 't1', sha256: str_repeat('a', 64));
        $reached = true;
    });

    expect($reached)->toBeTrue();
});
