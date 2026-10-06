<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * Cross-scope deletion safety (H.3 / H.6): under per-tenant dedup the SAME bytes
 * in two tenants are two rows at two scope-qualified keys. Sweeping tenant 1's
 * blob must never delete tenant 2's object — the scope segment in storage_key is
 * what guarantees it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('sweep одного scope не трогает байты другого scope с теми же байтами', function (): void {
    $store = app(BlobStore::class);
    $gc = app(BlobGarbageCollector::class);

    $a = $store->commit(CasTestHelpers::streamFor('cross-scope'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $b = $store->commit(CasTestHelpers::streamFor('cross-scope'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 2, 'unused'));

    // Two distinct physical objects despite identical bytes.
    expect($a->storage_key)->not->toBe($b->storage_key);
    Storage::disk('local')->assertExists($a->storage_key);
    Storage::disk('local')->assertExists($b->storage_key);

    // Tenant 1's blob becomes a sweep candidate; tenant 2's stays committed.
    $a->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subMinute()])->save();

    $gc->sweep();

    // A's bytes gone, B's bytes intact — no cross-scope deletion.
    Storage::disk('local')->assertMissing($a->storage_key);
    Storage::disk('local')->assertExists($b->storage_key);
    expect($b->fresh()->status)->toBe(BlobStatus::Committed);
});
