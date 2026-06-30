<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Tests\Cas\CasTestHelpers;

/**
 * The dedup scope (D18 / H.3) decides what shares one physical object: global
 * (sha256 alone), tenant (tenant_id + sha256) or storage (storage_id + sha256).
 * The scope-qualified storage_key keeps two scopes from ever colliding on one
 * object, so one scope's GC can never touch another's bytes.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('global: одинаковые байты в разных тенантах → ОДИН блоб', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 1);
    $b = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 2);

    expect($b->getKey())->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(1)
        ->and($a->storage_key)->toStartWith('global/');
});

it('tenant: одинаковые байты в разных тенантах → ДВА блоба, два объекта', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 1);
    $b = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 2);

    expect($b->getKey())->not->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(2)
        ->and($a->sha256)->toBe($b->sha256)
        ->and($a->storage_key)->not->toBe($b->storage_key)
        ->and($a->storage_key)->toStartWith('t1/')
        ->and($b->storage_key)->toStartWith('t2/')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('storage: одинаковые байты в разных хранилищах → ДВА блоба', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), storageId: 10);
    $b = $store->commit(CasTestHelpers::streamFor('shared'), storageId: 20);

    expect($b->getKey())->not->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(2)
        ->and($a->storage_key)->toStartWith('s10/')
        ->and($b->storage_key)->toStartWith('s20/')
        ->and($a->dedup_scope)->toBe('storage');
});

it('storage-scope ключит на storage_id, не на tenant_id', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    $store = app(BlobStore::class);

    // Same storage, different tenants → still ONE blob (tenant is irrelevant in
    // storage scope).
    $a = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 1, storageId: 10);
    $b = $store->commit(CasTestHelpers::streamFor('shared'), tenantId: 2, storageId: 10);

    expect($b->getKey())->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(1);
});
