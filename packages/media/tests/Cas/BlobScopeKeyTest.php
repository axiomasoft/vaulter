<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Schema\IdStrategyResolver;

it('isolates real identifier strategies across dedup scopes and GC', function (string $strategy, string $dedup): void {
    config()->set('vaulter.ids.strategy', $strategy);
    config()->set('vaulter-media.dedup.scope', $dedup);
    $db = app(VaulterDatabase::class)->connection();
    expect($this->app->environment('testing'))->toBeTrue();
    expect($db->getDatabaseName() === ':memory:' || str_ends_with($db->getDatabaseName(), '_test'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Storage::fake('local');
    // Opaque storage identifiers of the strategy (media knows no drive): each is its own tenant too.
    $ids = app(IdStrategyResolver::class)->resolve($strategy);
    $aStorage = (string) ($ids->newId() ?? 101);
    $bStorage = (string) ($ids->newId() ?? 102);
    $aVersion = CasTestHelpers::reference();
    $bVersion = CasTestHelpers::reference();
    $store = app(BlobStore::class);
    $a = $store->commit(CasTestHelpers::streamFor('same bytes'), BlobScope::forUpload($dedup, $aStorage, $aStorage));
    $b = $store->commit(CasTestHelpers::streamFor('same bytes'), BlobScope::forUpload($dedup, $bStorage, $bStorage));
    CasTestHelpers::pin($a, $aVersion);
    CasTestHelpers::pin($b, $bVersion);
    expect($a->scope_key)->not->toBe($b->scope_key)
        ->and($a->getKey())->not->toBe($b->getKey())->and($a->storage_key)->not->toBe($b->storage_key);
    expect(app(BlobReconciler::class)->sweepOrphans('local', 0, false)['orphans'])->toBe([]);
    // A rowless object must actually be recognized in each new scope layout.
    $orphanKeys = [];
    foreach ([$aStorage, $bStorage] as $index => $storage) {
        $scope = BlobScope::forUpload($dedup, $storage, $storage);
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
    // Release the first owner's pin, then sweep its past-grace tombstone.
    CasTestHelpers::unpin($a, $aVersion);
    $a->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subHour()])->save();
    app(BlobGarbageCollector::class)->sweep();
    Storage::disk($b->disk)->assertExists($b->storage_key);
    expect(stream_get_contents($store->read($b)))->toBe('same bytes');
})->with([
    ['bigint', 'off'], ['bigint', 'tenant'], ['bigint', 'drive'],
    ['ulid', 'off'], ['ulid', 'tenant'], ['ulid', 'drive'],
    ['uuid7', 'off'], ['uuid7', 'tenant'], ['uuid7', 'drive'],
]);
