<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Enums\BlobStatus;
use Vaulter\Models\Blob;

/**
 * Blob dedup-uniqueness is scoped (D18 / H.3): the same sha256 is unique only
 * within (tenant_id, storage_id), so identical bytes in two scopes are two rows
 * and storage_key is scope-qualified so they never share one physical object.
 */
function makeBlob(array $overrides = []): Blob
{
    return Blob::query()->create([
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 10,
        'storage_key' => 'global/aa/aa/'.str_repeat('a', 64),
        ...$overrides,
    ]);
}

it('отвергает одинаковый sha256 в одном scope', function (): void {
    makeBlob(['tenant_id' => 1, 'storage_id' => 1, 'storage_key' => 't1/aa/aa/x']);

    expect(fn () => makeBlob(['tenant_id' => 1, 'storage_id' => 1, 'storage_key' => 't1/aa/aa/y']))
        ->toThrow(QueryException::class);
});

it('допускает одинаковый sha256 в разных scope', function (): void {
    $a = makeBlob(['tenant_id' => 1, 'storage_id' => null, 'storage_key' => 't1/aa/aa/a']);
    $b = makeBlob(['tenant_id' => 2, 'storage_id' => null, 'storage_key' => 't2/aa/aa/b']);

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
