<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\ValueObjects\Cas\BlobScope;

it('isolates real identifier strategies across dedup scopes and GC', function (string $strategy, string $dedup): void {
    config()->set('vaulter.ids.strategy', $strategy);
    config()->set('vaulter-media.dedup.scope', $dedup);
    $db = app(VaulterDatabase::class)->connection();
    expect($this->app->environment('testing'))->toBeTrue();
    expect($db->getDatabaseName() === ':memory:' || str_ends_with($db->getDatabaseName(), '_test'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Storage::fake('local');
    $aVersion = CasTestHelpers::makeVersion();
    $bVersion = CasTestHelpers::makeVersion();
    $aStorage = $aVersion->file->node->storage;
    $bStorage = $bVersion->file->node->storage;
    $aStorage->forceFill(['tenant_id' => (string) $aStorage->getKey(), 'owner_id' => (string) $aStorage->getKey()])->save();
    $bStorage->forceFill(['tenant_id' => (string) $bStorage->getKey(), 'owner_id' => (string) $bStorage->getKey()])->save();
    $store = app(BlobStore::class);
    $a = $store->commit(CasTestHelpers::streamFor('same bytes'), BlobScope::forUpload($dedup, $aStorage->tenant_id, (string) $aStorage->getKey()));
    $b = $store->commit(CasTestHelpers::streamFor('same bytes'), BlobScope::forUpload($dedup, $bStorage->tenant_id, (string) $bStorage->getKey()));
    $store->attach($aVersion, $a);
    $store->attach($bVersion, $b);
    if ($dedup === 'global') {
        expect($a->getKey())->toBe($b->getKey());
    } else {
        expect($a->scope_key)->not->toBe($b->scope_key)
            ->and($a->getKey())->not->toBe($b->getKey())->and($a->storage_key)->not->toBe($b->storage_key);
    }
    expect(app(BlobReconciler::class)->sweepOrphans('local', 0, false)['orphans'])->toBe([]);
    // A rowless object must actually be recognized in each new scope layout.
    $orphanKeys = [];
    foreach ([$aStorage, $bStorage] as $index => $storage) {
        $scope = BlobScope::forUpload($dedup, $storage->tenant_id, (string) $storage->getKey());
        $key = app(ContentStore::class)->keyFor($scope, hash('sha256', 'orphan '.$index));
        Storage::disk('local')->put($key, 'orphan '.$index);
        CasTestHelpers::ageObject($key, 48);
        $orphanKeys[] = $key;
    }
    $orphans = app(BlobReconciler::class)->sweepOrphans('local', 0, false)['orphans'];
    sort($orphans);
    sort($orphanKeys);
    expect($orphans)->toBe($orphanKeys);
    Storage::disk('local')->delete($orphanKeys);
    // Purge the first revision's edges, then sweep its past-grace tombstone.
    $aVersion->delete();
    if ($dedup !== 'global') {
        $a->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subHour()])->save();
    }
    app(BlobGarbageCollector::class)->sweep();
    Storage::disk($b->disk)->assertExists($b->storage_key);
    expect(stream_get_contents($store->read($b)))->toBe('same bytes');
})->with([
    ['bigint', 'global'], ['bigint', 'tenant'], ['bigint', 'storage'],
    ['ulid', 'global'], ['ulid', 'tenant'], ['ulid', 'storage'],
    ['uuid7', 'global'], ['uuid7', 'tenant'], ['uuid7', 'storage'],
]);
