<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobLock;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * find-or-create regressions: the row id is always real (the ON CONFLICT
 * RETURNING null-id gotcha is avoided), the INSERT-race loser re-SELECTs and
 * reuses, and a tombstoned match is resurrected — all inside one locked
 * transaction.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('returns the REAL id (regression for the null id from ON CONFLICT DO NOTHING)', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'real id');

    $result = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha,
        sizeBytes: 7,
        storageKey: "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}",
        disk: 'local',
        scope: BlobScope::tenant('1'),
        tenantId: '1',
        storageId: null,
    ));

    expect($result['created'])->toBeTrue()
        ->and($result['blob']->getKey())->not->toBeNull()
        ->and($result['blob']->exists)->toBeTrue()
        ->and($result['blob']->status)->toBe(BlobStatus::Committed);
});

it('a second find-or-create of the same bytes gives created=false and the same id', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'second hit');
    $key = "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}";

    $first = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 10, storageKey: $key, disk: 'local',
        scope: BlobScope::tenant('1'), tenantId: '1', storageId: null,
    ));

    $second = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 10, storageKey: $key, disk: 'local',
        scope: BlobScope::tenant('1'), tenantId: '1', storageId: null,
    ));

    expect($second['created'])->toBeFalse()
        ->and($second['blob']->getKey())->toBe($first['blob']->getKey())
        ->and(Blob::query()->count())->toBe(1);
});

it('find-or-create on a tombstoned row resurrects it', function (): void {
    $repo = app(BlobStoreRepository::class);
    $sha = hash('sha256', 'resurrect-me');
    $key = "t1/{$sha[0]}{$sha[1]}/{$sha[2]}{$sha[3]}/{$sha}";

    $created = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 5, storageKey: $key, disk: 'local',
        scope: BlobScope::tenant('1'), tenantId: '1', storageId: null,
    ));

    Blob::query()->whereKey($created['blob']->getKey())->update([
        'status' => BlobStatus::Tombstoned->value,
        'delete_after_at' => now()->addHour(),
    ]);

    $again = DB::transaction(fn (): array => $repo->findOrCreate(
        sha256: $sha, sizeBytes: 5, storageKey: $key, disk: 'local',
        scope: BlobScope::tenant('1'), tenantId: '1', storageId: null,
    ));

    expect($again['created'])->toBeFalse()
        ->and($again['blob']->status)->toBe(BlobStatus::Committed)
        ->and($again['blob']->delete_after_at)->toBeNull();
});

it('BlobLock on sqlite: the advisory lock is a no-op and the single writer serializes', function (): void {
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
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'asserts the SQLite no-op lock premise; on PG/MySQL the lock is a real mutex.',
);
