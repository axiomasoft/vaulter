<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Vaulter\Attributes\Internal;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\ListableStorageDriver;
use Vaulter\Media\Contracts\Cas\StorageDriver;

/**
 * The default StorageDriver: a thin Flysystem-disk abstraction. The disk is
 * resolved per call (a blob's v_drives.disk, else the install default); writes
 * are stream writes; the conditional-create / object-store checksum specifics are
 * applied only when the underlying disk is an object store, degrading gracefully
 * to a best-effort exists() guard on the local / sqlite-test disk.
 */
#[Internal]
final class FlysystemStorageDriver implements ListableStorageDriver, StorageDriver
{
    public function __construct(
        private readonly MediaConfig $mediaConfig,
    ) {}

    public function disk(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? $this->mediaConfig->disk());
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
        // "already there" race as a false return (re-SELECT + reuse). On a
        // local/sqlite test disk there is no conditional PUT, so we degrade to a
        // best-effort exists() guard — the per-(scope,sha256) lock in BlobStore is
        // the real serialiser there.
        if ($ifNoneMatch && $filesystem->exists($key)) {
            return false;
        }

        // Object stores land a write ATOMICALLY server-side: a failed/partial PUT
        // never appears under the key, so there is no truncated-object window to
        // guard — and a sibling temp + move there would only double the transfer
        // and, past the S3 5 GiB single-request CopyObject limit, make the move FAIL
        // where a direct PUT succeeds. Write straight to the canonical key on those
        // disks; reserve temp-then-move for non-atomic disks (local/ftp) where a
        // crash mid-write WOULD leave a truncated object under the canonical CAS key
        // (review def.2).
        if ($this->writesAtomically($filesystem)) {
            // x-amz-checksum-sha256 (in $options) lets S3 verify the bytes server-side.
            $written = $filesystem->writeStream($key, $stream, $options);

            return $written !== false;
        }

        return $this->promoteViaTemp($filesystem, $key, $stream, $ifNoneMatch, $options);
    }

    /**
     * Temp-then-atomic-move promotion for a NON-atomic disk: a crash
     * mid-write must never leave a TRUNCATED object under the canonical CAS key (a
     * partial object there would be trusted as committed — silent CAS corruption). We
     * stream into a sibling temp key and only atomically move it onto the canonical
     * key once the whole object landed; ANY failure — writeStream false/throw OR move
     * false/throw — reclaims the temp and never leaves a partial under the canonical
     * key (review def.1 + def.2). The temp is a sibling (same directory ⇒ same
     * filesystem) so the move is a rename on the local disk.
     *
     * @param resource             $stream
     * @param array<string, mixed> $options
     */
    private function promoteViaTemp(Filesystem $filesystem, string $key, mixed $stream, bool $ifNoneMatch, array $options): bool
    {
        $tempKey = $this->tempKeyFor($key);

        try {
            $written = $filesystem->writeStream($tempKey, $stream, $options);

            if ($written === false) {
                $this->deleteQuietly($filesystem, $tempKey);

                return false;
            }
        } catch (\Throwable $exception) {
            // Reclaim the partial temp; the canonical key was never written.
            $this->deleteQuietly($filesystem, $tempKey);

            throw $exception;
        }

        // Conditional-create only (CAS promote, $ifNoneMatch): a concurrent uploader
        // of the SAME content-address may have promoted its (byte-identical) object
        // first, so drop our temp and treat it as a dedup hit rather than clobber it.
        // The plain overwrite path (e.g. an S3 part re-upload REPLACING its bytes)
        // must fall through and move over the existing key.
        if ($ifNoneMatch && $filesystem->exists($key)) {
            $this->deleteQuietly($filesystem, $tempKey);

            return true;
        }

        // Atomic promotion: a rename on the local disk (same directory ⇒ same
        // filesystem), overwriting any existing object for the overwrite path. move()
        // returns FALSE on a non-throwing disk and THROWS UnableToMoveFile on a
        // throwing one; a leaked temp on either path would linger under a sibling key,
        // so reclaim it and report failure both ways rather than falsely returning
        // success while the canonical key was never written (review def.1 + def.2).
        try {
            $moved = $filesystem->move($tempKey, $key);
        } catch (\Throwable $exception) {
            $this->deleteQuietly($filesystem, $tempKey);

            throw $exception;
        }

        if ($moved === false) {
            $this->deleteQuietly($filesystem, $tempKey);

            return false;
        }

        return true;
    }

    /**
     * Whether the disk lands a write atomically (review def.2). An object
     * store commits a PUT server-side — a failed upload never appears under the key —
     * so it needs no temp-then-move staging. A local/ftp disk, and any disk we cannot
     * positively identify as an object store, is treated as non-atomic so the
     * truncated-object guard still applies. We reuse the same object-store signal this
     * driver already trusts for signed URLs (temporaryUrl()): providesTemporaryUrls().
     */
    private function writesAtomically(Filesystem $filesystem): bool
    {
        return $filesystem instanceof FilesystemAdapter && $filesystem->providesTemporaryUrls();
    }

    /**
     * A sibling temp key for the atomic-move promotion: same directory
     * as the canonical key (so the move is a same-filesystem rename on local) with
     * a random suffix so two concurrent promotions never collide on the temp.
     */
    private function tempKeyFor(string $key): string
    {
        return $key.'.tmp-'.bin2hex(random_bytes(8));
    }

    private function deleteQuietly(Filesystem $filesystem, string $key): void
    {
        if ($filesystem->exists($key)) {
            $filesystem->delete($key);
        }
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

    public function lastModified(string $key, ?string $disk = null): ?int
    {
        $filesystem = $this->disk($disk);

        if (!$filesystem->exists($key)) {
            return null;
        }

        // The Flysystem-backed adapter returns the mtime as epoch seconds; a disk
        // that cannot report it throws, which we swallow to a null (treat as
        // "age unknown" → the caller skips rather than deletes).
        try {
            return $filesystem->lastModified($key);
        } catch (UnableToRetrieveMetadata) {
            return null;
        }
    }

    /**
     * @return iterable<string>
     */
    public function listContents(?string $disk = null): iterable
    {
        $filesystem = $this->disk($disk);

        // The recursive listing lives on the wrapped Flysystem operator, not the
        // Laravel contract; a disk whose adapter we cannot reach yields nothing
        // rather than throwing (the orphan walk then finds no candidates there).
        if (!$filesystem instanceof FilesystemAdapter) {
            return;
        }

        foreach ($filesystem->getDriver()->listContents('', deep: true) as $attributes) {
            if ($attributes->isFile()) {
                yield $attributes->path();
            }
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
