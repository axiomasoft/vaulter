<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Cas\Blob;

/**
 * Blob dedup-uniqueness is scoped (D18 / H.3): the same sha256 is unique only
 * within (tenant_id, drive_id), so identical bytes in two scopes are two rows
 * and storage_key is scope-qualified so they never share one physical object.
 */
function makeBlob(array $overrides = []): Blob
{
    // Blob is fully guarded (P1.4/F-P1.5-04) — forceFill bypasses it for this
    // trusted test fixture builder, exactly like the production writer
    // (BlobStoreRepository) does.
    $blob = new Blob;
    $blob->forceFill([
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 10,
        'scope_key' => isset($overrides['tenant_id']) ? 'tenant:'.$overrides['tenant_id'] : 'global',
        'storage_key' => 'global/aa/aa/'.str_repeat('a', 64),
        ...$overrides,
    ]);
    $blob->save();

    return $blob;
}

it('отвергает одинаковый sha256 в одном scope', function (): void {
    makeBlob(['tenant_id' => 1, 'drive_id' => 1, 'storage_key' => 't1/aa/aa/x']);

    // Run the violating insert inside a SAVEPOINT (nested DB::transaction): on PostgreSQL
    // a UNIQUE violation aborts the WHOLE enclosing transaction — here the RefreshDatabase
    // wrapper — so a raw insert would poison the connection for the rest of the suite
    // ("current transaction is aborted"). The savepoint rolls back just the failed insert,
    // exactly as the production BlobStore commit does. SQLite tolerates either form.
    expect(fn () => DB::transaction(
        fn (): Blob => makeBlob(['tenant_id' => 1, 'drive_id' => 1, 'storage_key' => 't1/aa/aa/y']),
    ))->toThrow(QueryException::class);
});

it('допускает одинаковый sha256 в разных scope', function (): void {
    $a = makeBlob(['tenant_id' => 1, 'drive_id' => null, 'storage_key' => 't1/aa/aa/a']);
    $b = makeBlob(['tenant_id' => 2, 'drive_id' => null, 'storage_key' => 't2/aa/aa/b']);

    expect($a->exists)->toBeTrue()
        ->and($b->exists)->toBeTrue()
        ->and($a->sha256)->toBe($b->sha256)
        ->and($a->storage_key)->not->toBe($b->storage_key);
});

it('хранит state-колонки блоба для CAS-логики M1b', function (): void {
    // Read back from the DB so the schema-level column defaults are asserted.
    $blob = makeBlob(['tenant_id' => 9])->fresh();

    expect($blob->status)->toBe(BlobStatus::Pending)
        ->and($blob->ref_count)->toBe(0)
        ->and($blob->dedup_scope)->toBe('tenant')
        ->and($blob->delete_after_at)->toBeNull();
});
