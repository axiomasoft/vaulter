<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Fixtures\Cas;

use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\ListableContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

/**
 * A listable {@see ContentStore} whose {@see lastModified()} ALWAYS reports null —
 * an object whose age the disk cannot establish (an adapter that does not expose
 * mtime, or a transient metadata failure swallowed to null by the driver).
 *
 * The orphan grace must treat "age unknown" as NOT-past-grace, so such an object is
 * SKIPPED, never deleted (the data-safety conservative default). Binding this proves
 * {@see BlobReconciler::sweepOrphans()} never reports a null-mtime
 * object as an orphan, even when it otherwise matches the canonical key layout and
 * has no blob row.
 *
 * Everything else (staging, promotion, keyFor, and crucially listKeys) delegates to
 * a real {@see Sha256ContentStore} so the walk still SEES the object on disk.
 */
final readonly class NullMtimeContentStore implements ContentStore, ListableContentStore
{
    public function __construct(
        private ContentStore $inner = new Sha256ContentStore(new FlysystemStorageDriver),
    ) {}

    /**
     * Always null — the whole point of the fixture (age unknown ⇒ caller skips).
     */
    public function lastModified(string $key, ?string $disk = null): ?int
    {
        return null;
    }

    /**
     * The real recursive disk listing — the object must still be enumerated so the
     * grace gate (not the listing gate) is what skips it.
     *
     * @return iterable<string>
     */
    public function listKeys(?string $disk = null): iterable
    {
        /** @var Sha256ContentStore $inner */
        $inner = $this->inner;

        yield from $inner->listKeys($disk);
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

    public function keyFor(string $scope, string $sha256): string
    {
        return $this->inner->keyFor($scope, $sha256);
    }
}
