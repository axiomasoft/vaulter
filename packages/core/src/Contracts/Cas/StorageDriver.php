<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * A thin abstraction over a Flysystem disk (H.3): the byte plane under the CAS.
 * The disk to use is resolved per storage (v_drives.disk) or the install
 * default; everything else is a straight Flysystem call. Swap the implementation
 * to add object-store specifics (x-amz-checksum-sha256, If-None-Match: *) while
 * keeping the local/sqlite test disk working unchanged.
 */
interface StorageDriver
{
    /**
     * The resolved Flysystem disk for the given disk name (null → the install
     * default disk, config('vaulter.storage.default_disk')).
     */
    public function disk(?string $disk = null): Filesystem;

    /**
     * Write bytes to $key on $disk. When $ifNoneMatch is true the driver attempts
     * a conditional create (If-None-Match: * on S3); a 412/409 means we were
     * overtaken and must re-SELECT + reuse (H.3) — surfaced as a return of false,
     * not an exception. On a local disk the condition degrades gracefully to a
     * best-effort exists() guard.
     *
     * @param resource             $stream
     * @param array<string, mixed> $options
     */
    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool;

    /**
     * A read stream over the object at $key, or null when it is absent.
     *
     * @return resource|null
     */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    /**
     * A short-lived signed URL for the object, when the disk supports it; null on
     * disks (local/sqlite test) that cannot mint one.
     */
    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt, ?string $disk = null): ?string;
}
