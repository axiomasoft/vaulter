<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The dedup scope (D18 / H.3, PLAN2.D49) decides what shares one physical object:
 * tenant (tenant_id + sha256; a drive without a tenant keeps to itself), storage
 * (drive_id + sha256) or off (nothing). Nothing is shared across tenants.
 * The scope-qualified storage_key keeps two scopes from ever colliding on one
 * object, so one scope's GC can never touch another's bytes.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('global больше не строится: нет общей области между тенантами', function (): void {
    expect(fn () => BlobScope::forUpload('global', '1', '10'))->toThrow(InvalidArgumentException::class);
});

it('tenant без тенанта: одинаковые байты в разных дисках → ДВА блоба', function (): void {
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload('tenant', null, (string) 10));
    $b = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload('tenant', null, (string) 20));
    $c = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload('tenant', null, (string) 10));

    expect($b->getKey())->not->toBe($a->getKey())
        ->and($c->getKey())->toBe($a->getKey())
        ->and($a->storage_key)->toStartWith('d-10/')
        ->and($b->storage_key)->toStartWith('d-20/');
});

it('off: одинаковые байты одного диска → ДВА блоба', function (): void {
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload('off', '1', (string) 10));
    $b = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload('off', '1', (string) 10));

    expect($b->getKey())->not->toBe($a->getKey())
        ->and($a->dedup_scope)->toBe('off')
        ->and($a->storage_key)->toStartWith('o-')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('tenant: одинаковые байты в разных тенантах → ДВА блоба, два объекта', function (): void {
    config()->set('vaulter-media.dedup.scope', 'tenant');
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $b = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 2, 'unused'));

    expect($b->getKey())->not->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(2)
        ->and($a->sha256)->toBe($b->sha256)
        ->and($a->storage_key)->not->toBe($b->storage_key)
        ->and($a->storage_key)->toStartWith('t-1/')
        ->and($b->storage_key)->toStartWith('t-2/')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('storage: одинаковые байты в разных хранилищах → ДВА блоба', function (): void {
    config()->set('vaulter-media.dedup.scope', 'drive');
    $store = app(BlobStore::class);

    $a = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), null, (string) 10));
    $b = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), null, (string) 20));

    expect($b->getKey())->not->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(2)
        ->and($a->storage_key)->toStartWith('d-10/')
        ->and($b->storage_key)->toStartWith('d-20/')
        ->and($a->dedup_scope)->toBe('drive')
        ->and((string) $a->drive_id)->toBe('10')
        ->and($a->tenant_id)->toBeNull()
        ->and((string) $b->drive_id)->toBe('20')
        ->and($b->tenant_id)->toBeNull();
});

it('storage-scope ключит на drive_id, не на tenant_id', function (): void {
    config()->set('vaulter-media.dedup.scope', 'drive');
    $store = app(BlobStore::class);

    // Same storage, different tenants → still ONE blob (tenant is irrelevant in
    // storage scope).
    $a = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, (string) 10));
    $b = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 2, (string) 10));

    expect($b->getKey())->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(1);
});
