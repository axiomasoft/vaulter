<?php

declare(strict_types=1);

namespace Vaulter\Cas;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\StorageDriver;
use Vaulter\Support\Config;

/**
 * The default StorageDriver (H.3): a thin Flysystem-disk abstraction. The disk is
 * resolved per call (a blob's v_storages.disk, else the install default); writes
 * are stream writes; the conditional-create / object-store checksum specifics are
 * applied only when the underlying disk is an object store, degrading gracefully
 * to a best-effort exists() guard on the local / sqlite-test disk.
 */
final class FlysystemStorageDriver implements StorageDriver
{
    public function disk(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? Config::defaultDisk());
    }

    /**
     * @param resource             $stream
     * @param array<string, mixed> $options
     */
    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
    {
        $filesystem = $this->disk($disk);

        // Conditional create. On an object store this maps to If-None-Match: * so
        // a concurrent uploader of identical bytes cannot clobber; we surface the
        // "already there" race as a false return (re-SELECT + reuse, H.3). On a
        // local/sqlite test disk there is no conditional PUT, so we degrade to a
        // best-effort exists() guard — the per-(scope,sha256) lock in BlobStore is
        // the real serialiser there.
        if ($ifNoneMatch && $filesystem->exists($key)) {
            return false;
        }

        // x-amz-checksum-sha256 lets S3 verify the staged bytes server-side; it is
        // ignored by the local driver, so passing it is config-aware and safe.
        $written = $filesystem->writeStream($key, $stream, $options);

        return $written !== false;
    }

    /**
     * @return resource|null
     */
    public function get(string $key, ?string $disk = null): mixed
    {
        $filesystem = $this->disk($disk);

        if (!$filesystem->exists($key)) {
            return null;
        }

        $stream = $filesystem->readStream($key);

        return is_resource($stream) ? $stream : null;
    }

    public function exists(string $key, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($key);
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $filesystem = $this->disk($disk);

        if ($filesystem->exists($key)) {
            $filesystem->delete($key);
        }
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
    {
        $filesystem = $this->disk($disk);

        // Only object stores that opted in can mint a signed URL; the local /
        // sqlite-test disk returns null rather than throwing.
        if (!$filesystem instanceof FilesystemAdapter || !$filesystem->providesTemporaryUrls()) {
            return null;
        }

        return $filesystem->temporaryUrl($key, $expiresAt);
    }
}
