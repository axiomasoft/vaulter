<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Fixtures\Cas;

use Closure;
use RuntimeException;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Deletes the object, then fails — the sweep that dies between the unlink and its commit (D23). With $pause the
 * failure is replaced by a wait after the unlink: a sweep holding its lock with the bytes already gone.
 */
final class FailingDeleteContentStore implements ContentStore
{
    /** @param (Closure(): void)|null $pause */
    public function __construct(private ContentStore $inner, private ?Closure $pause = null) {}

    public function stage(mixed $stream): StagedObject
    {
        return $this->inner->stage($stream);
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

        if ($this->pause !== null) {
            ($this->pause)();

            return;
        }

        throw new RuntimeException('injected failure after the delete');
    }

    public function keyFor(BlobScope $scope, string $sha256): string
    {
        return $this->inner->keyFor($scope, $sha256);
    }

    public function promote(StagedObject $staged, string $key, ?string $disk = null): void
    {
        $this->inner->promote($staged, $key, $disk);
    }
}
