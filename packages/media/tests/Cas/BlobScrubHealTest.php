<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobLock;
use Vaulter\Media\Cas\BlobScrubber;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * Healing: an ingest of the same sha256 into the same scope rewrites the object and lifts the integrity mark,
 * under the lock of (scope, sha256), as a re-commit lifts a tombstone. Other scopes and other bytes are untouched.
 * The rewrite lands on a sibling key before the canonical object is replaced, so a failed swap keeps the previous bytes.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function healCommit(string $bytes, string $tenant = '1'): Blob
{
    return CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant($tenant)));
}

/** A driver over the real one whose put() and delete() can be replaced. */
function healDriver(?Closure $put = null, ?Closure $delete = null, ?Closure $get = null): StorageDriver
{
    return new class(app(StorageDriver::class), $put, $delete, $get) implements StorageDriver
    {
        public function __construct(private readonly StorageDriver $inner, private readonly ?Closure $put, private readonly ?Closure $delete, private readonly ?Closure $get) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return $this->put !== null ? ($this->put)($key) : $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->get !== null ? ($this->get)($key, $disk) : $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            if ($this->delete === null) {
                $this->inner->delete($key, $disk);

                return;
            }

            ($this->delete)($key);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    };
}

function healStore(StorageDriver $driver): Vaulter\Media\Cas\BlobStore
{
    return new Vaulter\Media\Cas\BlobStore(
        new Sha256ContentStore($driver),
        app(BlobLock::class),
        app(BlobStoreRepository::class),
        app(VaulterDatabase::class),
        app(MediaOptions::class),
    );
}

/** Damage the object, let the scrub mark the blob. */
function healDamage(Blob $blob, ?string $contents): Blob
{
    $contents === null
        ? Storage::disk('local')->delete($blob->storage_key)
        : Storage::disk('local')->put($blob->storage_key, $contents);
    app(BlobScrubber::class)->scrub();

    return $blob->fresh();
}

it('rewrites a bad object and lifts the mark when the same bytes are ingested again', function (?string $damage): void {
    $blob = healDamage(healCommit('heal me'), $damage);
    expect($blob->isIntact())->toBeFalse();

    $healed = healCommit('heal me');

    expect($healed->getKey())->toBe($blob->getKey())
        ->and($healed->isIntact())->toBeTrue()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('heal me')
        ->and($blob->fresh()->integrity_failed_at)->toBeNull()
        // The check time is cleared as well, so the next scrub verifies the rewritten object first.
        ->and($blob->fresh()->integrity_checked_at)->toBeNull()
        ->and(app(BlobScrubber::class)->scrub())->toMatchArray(['checked' => 1, 'failures' => []])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with([
    'missing object' => [null],
    'wrong size' => ['heal'],
    'wrong digest' => ['heal you'],
]);

it('keeps the pins and the row of a healed blob', function (): void {
    $blob = healDamage(healCommit('pinned and bad'), 'pinned and BAD');
    CasTestHelpers::pin($blob);

    healCommit('pinned and bad');

    expect(CasTestHelpers::pins($blob))->toBe(1)
        ->and(Blob::query()->count())->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});

it('does not rewrite an object of an intact blob', function (): void {
    $blob = healCommit('intact stays');
    $modified = Storage::disk('local')->lastModified($blob->storage_key);
    Storage::disk('local')->put($blob->storage_key, 'intact stays');
    touch(Storage::disk('local')->path($blob->storage_key), $modified - 3600);

    healCommit('intact stays');

    expect(Storage::disk('local')->lastModified($blob->storage_key))->toBe($modified - 3600);
});

it('leaves the mark of another scope and of other bytes alone', function (): void {
    $other = healDamage(healCommit('same bytes', '2'), 'bent');
    $unrelated = healDamage(healCommit('unrelated bytes', '1'), null);

    healCommit('same bytes', '1');

    expect($other->fresh()->isIntact())->toBeFalse()
        ->and($unrelated->fresh()->isIntact())->toBeFalse()
        ->and(Storage::disk('local')->get($other->storage_key))->toBe('bent');
});

it('lifts the mark of a deleted blob when its bytes are ingested again', function (): void {
    $blob = healDamage(healCommit('gone and bad'), 'bad');
    CasTestHelpers::write(static fn () => $blob->forceFill(['status' => BlobStatus::Deleted])->save());
    Storage::disk('local')->delete($blob->storage_key);

    $healed = healCommit('gone and bad');

    expect($healed->getKey())->toBe($blob->getKey())
        ->and($healed->status)->toBe(BlobStatus::Committed)
        ->and($healed->fresh()->isIntact())->toBeTrue()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('gone and bad');
});

it('keeps the mark when the rewrite fails', function (): void {
    $blob = healDamage(healCommit('rewrite fails'), 'bad');
    $driver = Mockery::mock(ContentStore::class);
    $real = app(ContentStore::class);
    $driver->shouldReceive('stage')->andReturnUsing(static fn (mixed $stream) => $real->stage($stream));
    $driver->shouldReceive('keyFor')->andReturnUsing(static fn (BlobScope $scope, string $sha) => $real->keyFor($scope, $sha));
    $driver->shouldReceive('delete')->andReturnUsing(static fn (string $key, ?string $disk = null) => $real->delete($key, $disk));
    $driver->shouldReceive('exists')->andReturnUsing(static fn (string $key, ?string $disk = null) => $real->exists($key, $disk));
    $driver->shouldReceive('get')->andReturnUsing(static fn (string $key, ?string $disk = null) => $real->get($key, $disk));
    $driver->shouldReceive('promote')->andThrow(new RuntimeException('disk full'));
    $store = new Vaulter\Media\Cas\BlobStore(
        $driver,
        app(BlobLock::class),
        app(BlobStoreRepository::class),
        app(VaulterDatabase::class),
        app(MediaOptions::class),
    );

    expect(fn (): Blob => CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('rewrite fails'), BlobScope::tenant('1'))))->toThrow(RuntimeException::class, 'disk full')
        ->and($blob->fresh()->isIntact())->toBeFalse()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('bad');
});

it('keeps the mark and the previous object when the driver reports a failed write', function (): void {
    $blob = healDamage(healCommit('write reports false'), 'bad');
    $inner = app(StorageDriver::class);
    $failing = new class($inner) implements StorageDriver
    {
        public function __construct(private readonly StorageDriver $inner) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return false;
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    };
    $store = new Vaulter\Media\Cas\BlobStore(
        new Sha256ContentStore($failing),
        app(BlobLock::class),
        app(BlobStoreRepository::class),
        app(VaulterDatabase::class),
        app(MediaOptions::class),
    );

    expect(fn (): Blob => CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('write reports false'), BlobScope::tenant('1'))))->toThrow(RuntimeException::class)
        ->and($blob->fresh()->integrity_failed_at)->not->toBeNull()
        ->and($blob->fresh()->isIntact())->toBeFalse()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('bad');
});

it('keeps the mark when the store does not remove the bad object', function (): void {
    $blob = healDamage(healCommit('undeletable'), 'bad object!');
    // A Flysystem disk that does not throw reports a failed delete as false, which the driver does not pass on: the
    // bad object stays, and the conditional promote keeps it.
    $store = healStore(healDriver(delete: static function (): void {}));

    expect(fn (): Blob => CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('undeletable'), BlobScope::tenant('1'))))
        ->toThrow(RuntimeException::class, 'was not healed')
        ->and($blob->fresh()->isIntact())->toBeFalse()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('bad object!');
});

it('leaves the object alone when the mark was lifted after the commit read the row', function (): void {
    $blob = healDamage(healCommit('healed by another'), 'bad');
    $deletes = 0;
    // The write of this commit would fail: a deleted object would be lost, not restored.
    $store = healStore(healDriver(put: static fn (): bool => false, delete: static function () use (&$deletes): void {
        $deletes++;
    }));
    $lifted = false;
    // Another heal lands between the read of the row and the decision to rewrite the object (on SQLite: a commit whose
    // snapshot is older than that heal's).
    app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use (&$lifted, $blob): void {
        if ($lifted || !str_starts_with($query->sql, 'select') || !str_contains($query->sql, $blob->getTable()) || !str_contains($query->sql, 'sha256')) {
            return;
        }

        $lifted = true;
        Storage::disk('local')->put($blob->storage_key, 'healed by another');
        CasTestHelpers::write(static fn () => $blob->newQuery()->whereKey($blob->getKey())->update(['integrity_failed_at' => null, 'integrity_checked_at' => null]));
    });

    $committed = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('healed by another'), BlobScope::tenant('1')));

    expect($lifted)->toBeTrue()
        ->and($deletes)->toBe(0)
        ->and($committed->isIntact())->toBeTrue()
        ->and($blob->fresh()->isIntact())->toBeTrue()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('healed by another');
});

it('rewrites a same-length corrupt object on a dedup hit and leaves the row unmarked', function (): void {
    $blob = healCommit('same len');
    Storage::disk('local')->put($blob->storage_key, 'SAME LEN');
    expect($blob->fresh()->integrity_failed_at)->toBeNull();

    $again = healCommit('same len');

    expect($again->getKey())->toBe($blob->getKey())
        ->and($again->isIntact())->toBeTrue()
        ->and($blob->fresh()->integrity_failed_at)->toBeNull()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('same len')
        ->and(stream_get_contents(app(BlobStore::class)->read($again->getKey())))->toBe('same len')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('does not succeed a dedup hit while a same-length object still mismatches', function (): void {
    $blob = healCommit('same len');
    Storage::disk('local')->put($blob->storage_key, 'SAME LEN');
    $store = healStore(healDriver(put: static fn (): bool => false));

    expect(fn (): Blob => CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same len'), BlobScope::tenant('1'))))
        ->toThrow(RuntimeException::class)
        ->and($blob->fresh()->integrity_failed_at)->toBeNull()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('SAME LEN');
});

final class StalledBlobVerificationStream
{
    public $context;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if (++$this->reads > 1) {
            throw new RuntimeException('Read the stalled object again.');
        }

        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

it('refuses a stalled dedup verification without repeatedly reading or replacing the object', function (): void {
    $blob = healCommit('kept bytes');
    $inner = app(StorageDriver::class);
    stream_wrapper_register('vaulterstalled', StalledBlobVerificationStream::class);
    $store = healStore(healDriver(get: static fn (string $key, ?string $disk): mixed => $key === $blob->storage_key
        ? fopen('vaulterstalled://object', 'rb')
        : $inner->get($key, $disk)));

    try {
        expect(fn (): BlobSnapshot => $store->commit(CasTestHelpers::streamFor('kept bytes'), BlobScope::tenant('1')))
            ->toThrow(RuntimeException::class, 'Content stream made no progress.');
        expect(Storage::disk('local')->get($blob->storage_key))->toBe('kept bytes')
            ->and($blob->fresh()->isIntact())->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    } finally {
        stream_wrapper_unregister('vaulterstalled');
    }
});

it('refuses a dedup hit when the canonical object cannot be replaced', function (): void {
    $blob = healCommit('same len');
    Storage::disk('local')->put($blob->storage_key, 'SAME LEN');
    $store = healStore(healDriver(delete: static function (): void {}));

    expect(fn (): Blob => CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same len'), BlobScope::tenant('1'))))
        ->toThrow(RuntimeException::class, 'was not rewritten')
        ->and($blob->fresh()->integrity_failed_at)->toBeNull()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('SAME LEN');
});

it('keeps the mark and restores the previous object when the swap onto the canonical key fails', function (): void {
    $blob = healDamage(healCommit('restore me'), 'bad bytes');
    $canonical = $blob->storage_key;
    $inner = app(StorageDriver::class);
    $driver = new class($inner, $canonical) implements StorageDriver
    {
        private int $canonicalPuts = 0;

        public function __construct(private readonly StorageDriver $inner, private readonly string $canonical) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            if ($key === $this->canonical) {
                $this->canonicalPuts++;

                if ($this->canonicalPuts === 1) {
                    throw new RuntimeException('swap failed');
                }
            }

            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    };

    expect(fn (): Blob => CasTestHelpers::row(healStore($driver)->commit(CasTestHelpers::streamFor('restore me'), BlobScope::tenant('1'))))
        ->toThrow(RuntimeException::class, 'swap failed')
        ->and($blob->fresh()->isIntact())->toBeFalse()
        ->and(Storage::disk('local')->get($canonical))->toBe('bad bytes')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});
