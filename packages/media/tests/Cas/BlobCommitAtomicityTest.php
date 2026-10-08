<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * Commit atomicity (H.3): if the transaction rolls back, no blob row is left
 * behind. The staged temp file is always discarded. (The physical object is only
 * promoted for a newly created row, so a roll back before promotion leaves the
 * disk clean too.)
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('rollback не оставляет висящей строки blob', function (): void {
    // A ContentStore whose promote() blows up mid-transaction forces a rollback
    // after the row INSERT — proving the INSERT is inside the tx and reverts.
    $exploding = new class implements ContentStore
    {
        private readonly ContentStore $inner;

        public function __construct(?ContentStore $inner = null)
        {
            $this->inner = $inner ?? new Sha256ContentStore(app(FlysystemStorageDriver::class));
        }

        public function stage(mixed $stream): StagedObject
        {
            return $this->inner->stage($stream);
        }

        public function promote(StagedObject $staged, string $key, ?string $disk = null): void
        {
            throw new RuntimeException('promote boom');
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return false; // force the promote branch
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function keyFor(BlobScope $scope, string $sha256): string
        {
            return $this->inner->keyFor($scope, $sha256);
        }
    };

    $store = new BlobStore(
        content: $exploding,
        lock: app(BlobLock::class),
        blobs: app(BlobStoreRepository::class),
        edges: app(FileRevisionBlobStoreRepository::class),
        database: app(VaulterDatabase::class),
        mediaConfig: app(MediaConfig::class),
    );

    expect(fn () => $store->commit(stringStream('boom bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')))
        ->toThrow(RuntimeException::class, 'promote boom');

    // The transaction rolled back: zero rows, zero objects.
    expect(Blob::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('staged temp-файл удаляется после успешного коммита', function (): void {
    $before = glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [];

    app(BlobStore::class)->commit(stringStream('temp cleanup'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));

    $after = glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [];

    expect(count($after))->toBeLessThanOrEqual(count($before));
});

function stringStream(string $bytes)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('commitStaged keeps the temp file reusable across an outer SQL rollback', function (): void {
    $content = app(ContentStore::class);
    $staged = $content->stage(stringStream('reusable staged bytes'));
    $scope = BlobScope::tenant('1');
    try {
        expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($staged, $scope): void {
            app(BlobStore::class)->commitStaged($staged, $scope);
            throw new RuntimeException('metadata rollback');
        }))->toThrow(RuntimeException::class, 'metadata rollback');
        expect(Blob::query()->count())->toBe(0)
            ->and(is_file($staged->path()))->toBeTrue();
        $blob = app(BlobStore::class)->commitStaged($staged, $scope);
        expect(is_file($staged->path()))->toBeTrue()
            ->and(stream_get_contents(app(BlobStore::class)->read($blob)))->toBe('reusable staged bytes');
        expect(app(BlobStore::class)->commitStaged($staged, $scope)->id)->toBe($blob->id);
    } finally {
        $staged->discard();
    }
    expect(is_file($staged->path()))->toBeFalse();
});
