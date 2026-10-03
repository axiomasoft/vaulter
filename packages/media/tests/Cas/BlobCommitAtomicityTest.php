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
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileVersionBlobStoreRepository;

/**
 * Commit atomicity (H.3): if the transaction rolls back, no blob row is left
 * behind. The staged temp file is always discarded. (The physical object is only
 * promoted for a newly created row, so a roll back before promotion leaves the
 * disk clean too.)
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('rollback не оставляет висящей строки blob', function (): void {
    // A ContentStore whose promote() blows up mid-transaction forces a rollback
    // after the row INSERT — proving the INSERT is inside the tx and reverts.
    $exploding = new class implements ContentStore
    {
        public function __construct(private readonly ContentStore $inner = new Sha256ContentStore(new FlysystemStorageDriver)) {}

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

        public function keyFor(string $scope, string $sha256): string
        {
            return $this->inner->keyFor($scope, $sha256);
        }
    };

    $store = new BlobStore(
        content: $exploding,
        lock: app(BlobLock::class),
        blobs: app(BlobStoreRepository::class),
        edges: app(FileVersionBlobStoreRepository::class),
        database: app(VaulterDatabase::class),
    );

    expect(fn () => $store->commit(stringStream('boom bytes'), tenantId: 1))
        ->toThrow(RuntimeException::class, 'promote boom');

    // The transaction rolled back: zero rows, zero objects.
    expect(Blob::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('staged temp-файл удаляется после успешного коммита', function (): void {
    $before = glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [];

    app(BlobStore::class)->commit(stringStream('temp cleanup'), tenantId: 1);

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
