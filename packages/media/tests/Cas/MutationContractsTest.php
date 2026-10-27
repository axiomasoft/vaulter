<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobLock;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Media\Contracts\Cas\ListableStorageDriver;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.disk', 'local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-09-25 12:00:00');
});

it('stages the complete seekable stream and exposes its exact digest and size', function (): void {
    $stream = CasTestHelpers::streamFor('complete staged bytes');
    fread($stream, 8);

    $staged = app(Sha256ContentStore::class)->stage($stream);

    expect($staged->sha256())->toBe(hash('sha256', 'complete staged bytes'))
        ->and($staged->sizeBytes())->toBe(strlen('complete staged bytes'))
        ->and($staged->path())->toBeFile();

    $read = $staged->readStream();
    expect(stream_get_contents($read))->toBe('complete staged bytes');
    fclose($read);

    $path = $staged->path();
    $staged->discard();
    $staged->discard();

    expect($path)->not->toBeFile()
        ->and(fn () => $staged->readStream())
        ->toThrow(RuntimeException::class, 'Cannot read a discarded staged object.');
});

it('builds the canonical key and promotes the exact checksum and bytes', function (): void {
    $bytes = 'checksum contract';
    $sha256 = hash('sha256', $bytes);
    $stream = CasTestHelpers::streamFor($bytes);
    $driver = Mockery::mock(StorageDriver::class);
    $store = new Sha256ContentStore($driver);
    $staged = $store->stage($stream);
    $key = $store->keyFor(BlobScope::tenant('17'), $sha256);

    expect($key)->toBe('t-17/'.substr($sha256, 0, 2).'/'.$sha256);

    $driver->shouldReceive('exists')->once()->with($key, 'archive')->andReturn(false);
    $driver->shouldReceive('put')->once()->withArgs(
        function (string $actualKey, $actualStream, ?string $disk, bool $ifNoneMatch, array $options) use ($bytes, $key, $sha256): bool {
            return $actualKey === $key
                && is_resource($actualStream)
                && stream_get_contents($actualStream) === $bytes
                && $disk === 'archive'
                && $ifNoneMatch
                && $options === ['ChecksumSHA256' => base64_encode(hex2bin($sha256))];
        },
    )->andReturn(true);

    $store->promote($staged, $key, 'archive');
    $staged->discard();
});

it('does not promote bytes that already exist', function (): void {
    $driver = Mockery::mock(StorageDriver::class);
    $store = new Sha256ContentStore($driver);
    $staged = $store->stage(CasTestHelpers::streamFor('already promoted'));

    $driver->shouldReceive('exists')->once()->with('existing-key', null)->andReturn(true);
    $driver->shouldNotReceive('put');

    $store->promote($staged, 'existing-key');
    $staged->discard();
});

it('delegates object age and listing only to listable drivers', function (): void {
    $listable = Mockery::mock(StorageDriver::class, ListableStorageDriver::class);
    $listable->shouldReceive('lastModified')->once()->with('one', 'archive')->andReturn(1234);
    $listable->shouldReceive('listContents')->once()->with('archive')->andReturn(['one', 'two']);
    $store = new Sha256ContentStore($listable);

    expect($store->lastModified('one', 'archive'))->toBe(1234)
        ->and(iterator_to_array($store->listKeys('archive')))->toBe(['one', 'two']);

    $plain = Mockery::mock(StorageDriver::class);
    $plain->shouldNotReceive('lastModified', 'listContents');
    $plainStore = new Sha256ContentStore($plain);

    expect($plainStore->lastModified('one'))->toBeNull()
        ->and(iterator_to_array($plainStore->listKeys()))->toBe([]);
});

it('round-trips Flysystem bytes, metadata, listing and idempotent deletion', function (): void {
    $driver = app(FlysystemStorageDriver::class);
    $stream = CasTestHelpers::streamFor('flysystem bytes');

    expect($driver->put('aa/one.bin', $stream, 'local'))->toBeTrue()
        ->and($driver->exists('aa/one.bin', 'local'))->toBeTrue()
        ->and($driver->lastModified('aa/one.bin', 'local'))->toBeInt()
        ->and(iterator_to_array($driver->listContents('local')))->toBe(['aa/one.bin'])
        ->and($driver->temporaryUrl('aa/one.bin', now()->addMinute(), 'local'))->toContain('/aa/one.bin');

    $read = $driver->get('aa/one.bin', 'local');
    expect($read)->toBeResource()
        ->and(stream_get_contents($read))->toBe('flysystem bytes');
    fclose($read);

    $driver->delete('aa/one.bin', 'local');
    $driver->delete('aa/one.bin', 'local');

    expect($driver->exists('aa/one.bin', 'local'))->toBeFalse()
        ->and($driver->get('aa/one.bin', 'local'))->toBeNull()
        ->and($driver->lastModified('aa/one.bin', 'local'))->toBeNull();
});

it('preserves an existing object for a conditional Flysystem put', function (): void {
    Storage::disk('local')->put('same-key', 'winner bytes');
    $driver = app(FlysystemStorageDriver::class);

    $result = $driver->put(
        key: 'same-key',
        stream: CasTestHelpers::streamFor('loser bytes'),
        disk: 'local',
        ifNoneMatch: true,
    );

    expect($result)->toBeFalse()
        ->and(Storage::disk('local')->get('same-key'))->toBe('winner bytes');
});

it('re-promotes a deduplicated blob when its canonical object disappeared', function (): void {
    $store = app(BlobStoreContract::class);
    $first = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('restore missing bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 17, 'unused')));
    Storage::disk('local')->delete($first->storage_key);

    $second = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('restore missing bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 17, 'unused')));
    $read = $store->read($second->getKey());

    expect($second->getKey())->toBe($first->getKey())
        ->and($read)->toBeResource()
        ->and(stream_get_contents($read))->toBe('restore missing bytes');
    fclose($read);
});

it('keeps exact mark, sweep and pending totals across multiple candidates', function (): void {
    $store = app(BlobStoreContract::class);
    $collector = app(BlobGarbageCollector::class);

    $markOne = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('mark one'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $markTwo = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('mark two'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $referenced = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('referenced mark'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    CasTestHelpers::pin($referenced, CasTestHelpers::reference('mark-reference'));

    expect($collector->mark(graceHours: 24))->toBe(['tombstoned' => 2, 'skipped' => 1])
        ->and($markOne->fresh()->delete_after_at?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and($markTwo->fresh()->delete_after_at?->equalTo(now()->addHours(24)))->toBeTrue();

    foreach ([$markOne, $markTwo] as $blob) {
        $blob->forceFill(['delete_after_at' => now()->subMinute()])->save();
    }
    $referenced->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->subMinute(),
    ])->save();

    expect($collector->sweep())->toBe(['swept' => 2, 'skipped' => 1]);

    $pendingOne = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('pending one'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $pendingTwo = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('pending two'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $pendingLive = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('pending live'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    foreach ([$pendingOne, $pendingTwo, $pendingLive] as $blob) {
        $blob->forceFill([
            'status' => BlobStatus::Pending,
            'first_seen_at' => now()->subHours(3),
        ])->save();
    }
    // Seeded: through the protocol the pin would commit the pending row again.
    CasTestHelpers::seedPin($pendingLive, 'pending-reference');

    expect($collector->sweepPending(graceHours: 2))->toBe(['swept' => 2, 'skipped' => 1]);
});

it('reconciles every blob and preserves all dangling identities', function (): void {
    $store = app(BlobStoreContract::class);
    $counted = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('reconcile counted'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $zeroed = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('reconcile zeroed'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $dangling = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('reconcile dangling'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    CasTestHelpers::pin($counted, CasTestHelpers::reference('reconcile-a'));
    CasTestHelpers::pin($counted, CasTestHelpers::reference('reconcile-b'));
    $zeroed->forceFill(['ref_count' => 9])->save();
    Storage::disk('local')->delete($dangling->storage_key);

    $report = app(BlobReconciler::class)->reconcile();

    expect($report)->toBe([
        'reconciled' => 3,
        'changed' => 2,
        'dangling' => [(string) $dangling->getKey()],
    ])->and($counted->fresh()->ref_count)->toBe(2)
        ->and($zeroed->fresh()->ref_count)->toBe(0);
});

it('continues an orphan walk after every skipped object', function (): void {
    Storage::disk('local')->put('foreign/readme.txt', 'foreign');
    $owned = CasTestHelpers::seedBlobRow('owned bytes', BlobStatus::Committed);
    CasTestHelpers::ageObject($owned->storage_key, 48);
    CasTestHelpers::seedOrphan(bytes: 'fresh candidate', scope: 't-1', ageHours: 0);
    $firstOrphan = CasTestHelpers::seedOrphan(bytes: 'old candidate one', scope: 't-1', ageHours: 48);
    $secondOrphan = CasTestHelpers::seedOrphan(bytes: 'old candidate two', scope: 't-1', ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(
        disk: 'local',
        graceHours: 24,
        prune: false,
    );

    $orphans = $report['orphans'];
    unset($report['orphans']);

    expect($report)->toBe([
        'scanned' => 5,
        'pruned' => 0,
        'skipped' => 3,
        'listingSupported' => true,
        'pruneSupported' => app(BlobLock::class)->serializesCriticalSection(),
    ])->and($orphans)->toEqualCanonicalizing([$firstOrphan, $secondOrphan]);
});
