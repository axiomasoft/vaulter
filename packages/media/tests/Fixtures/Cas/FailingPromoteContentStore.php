<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Fixtures\Cas;

use RuntimeException;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

final class FailingPromoteContentStore implements ContentStore
{
    private int $promotes = 0;

    public function __construct(private ContentStore $inner, private int $failAt = 1, private bool $after = false) {}

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
    }

    public function keyFor(BlobScope $scope, string $sha256): string
    {
        return $this->inner->keyFor($scope, $sha256);
    }

    public function promote(StagedObject $staged, string $key, ?string $disk = null): void
    {
        $fail = ++$this->promotes === $this->failAt;
        if ($fail && !$this->after) {
            throw new RuntimeException('injected copy failure');
        }
        $this->inner->promote($staged, $key, $disk);
        if ($fail) {
            throw new RuntimeException('injected post-promote failure');
        }
    }
}
