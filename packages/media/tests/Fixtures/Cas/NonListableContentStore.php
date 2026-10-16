<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Fixtures\Cas;

use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * A {@see ContentStore} that DELIBERATELY does NOT implement
 * {@see ListableContentStore} — the conservative, BC-safe default for any external
 * driver that cannot enumerate its disk (a streaming-only / non-FilesystemAdapter
 * store, e.g. S3 without listing). Binding it proves the orphan disk-walk refuses
 * cleanly: {@see BlobReconciler::sweepOrphans()} returns
 * listingSupported=false, scans 0 and deletes nothing, rather than failing.
 *
 * Every byte-level method delegates to a real {@see Sha256ContentStore} so a
 * test can still commit/read normally; only the listing capability is withheld.
 */
final readonly class NonListableContentStore implements ContentStore
{
    private ContentStore $inner;

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
        $this->inner->promote($staged, $key, $disk);
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

    public function keyFor(BlobScope $scope, string $sha256): string
    {
        return $this->inner->keyFor($scope, $sha256);
    }
}
