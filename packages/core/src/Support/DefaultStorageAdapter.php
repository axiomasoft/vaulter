<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Contracts\Cas\StorageAdapter;

/**
 * No-op default. Real binary persistence (filesystem disk, S3, …) is wired in a
 * later milestone; host apps can bind their own {@see StorageAdapter} meanwhile.
 */
final class DefaultStorageAdapter implements StorageAdapter
{
    public function store(string $filename, mixed $contents): string
    {
        return $filename;
    }

    public function delete(string $url): void
    {
        // Skeleton: nothing to delete yet.
    }

    public function url(string $path): string
    {
        return $path;
    }
}
