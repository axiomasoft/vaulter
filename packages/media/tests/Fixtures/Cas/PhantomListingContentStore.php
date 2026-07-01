<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Fixtures\Cas;

use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\ListableContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

/**
 * A listable {@see ContentStore} that yields a PHANTOM key — a path the walk
 * enumerates and that reports an old mtime (so it clears the orphan grace), but whose
 * bytes are NOT actually on disk. It models the race the prune must survive: the
 * object was present at the cheap scan but is GONE by the time the under-lock physical
 * delete fires (a concurrent sweep/operator removed it).
 *
 * The prune's delete must be IDEMPOTENT — deleting an already-absent object is a
 * harmless no-op, never an exception ({@see FlysystemStorageDriver::delete()} guards
 * on exists()). This fixture lets a single-process test exercise that without a
 * concurrent harness: the delete delegates to the real store, which finds nothing.
 *
 * Every other method delegates to a real {@see Sha256ContentStore}.
 */
final readonly class PhantomListingContentStore implements ContentStore, ListableContentStore
{
    public function __construct(
        private string $phantomKey,
        private int $phantomMtime,
        private ContentStore $inner = new Sha256ContentStore(new FlysystemStorageDriver),
    ) {}

    /**
     * The phantom key is the only thing on this "disk".
     *
     * @return iterable<string>
     */
    public function listKeys(?string $disk = null): iterable
    {
        yield $this->phantomKey;
    }

    /**
     * A fixed past mtime for the phantom so the orphan grace classifies it as a
     * deletable orphan (the real store would report null for an absent object).
     */
    public function lastModified(string $key, ?string $disk = null): ?int
    {
        return $key === $this->phantomKey ? $this->phantomMtime : null;
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

    /**
     * Delegates to the real store — the phantom object is absent, so this is the
     * idempotent no-op the prune must tolerate.
     */
    public function delete(string $key, ?string $disk = null): void
    {
        $this->inner->delete($key, $disk);
    }

    public function keyFor(string $scope, string $sha256): string
    {
        return $this->inner->keyFor($scope, $sha256);
    }
}
