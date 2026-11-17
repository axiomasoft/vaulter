<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Packman\Attributes\Internal;
use RuntimeException;
use Throwable;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\ListableContentStore;
use Vaulter\Media\Contracts\Cas\ListableStorageDriver;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The default ContentStore: hash the LOCAL staged temp file, never the live
 * stream. An incoming stream is drained into a seekable temp file; the sha256 is
 * computed from those durable bytes (a lying client sha256 or an S3 multipart
 * ETag is irrelevant). Promotion writes the staged bytes to the canonical
 * scope-qualified key with a conditional create so a concurrent identical upload
 * cannot clobber, and on S3 attaches x-amz-checksum-sha256 for server-side
 * verification (config-aware; ignored by the local/sqlite test disk).
 */
#[Internal]
final readonly class Sha256ContentStore implements ContentStore, ListableContentStore
{
    /**
     * Read buffer for streaming the upload into the staged temp file.
     */
    private const int CHUNK_BYTES = 1_048_576;

    public function __construct(
        private StorageDriver $driver,
    ) {}

    /**
     * @param resource $stream
     */
    public function stage(mixed $stream): StagedObject
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('ContentStore::stage() expects an open stream resource.');
        }

        $path = $this->newTempPath();
        $target = @fopen($path, 'wb');

        if ($target === false) {
            throw new RuntimeException("Unable to open staging temp file [{$path}].");
        }

        $hash = hash_init('sha256');
        $size = 0;

        // Rewind the source where possible so a partially consumed stream still
        // stages whole; non-seekable streams (php://input) are read as-is.
        if (stream_get_meta_data($stream)['seekable']) {
            @rewind($stream);
        }

        try {
            while (!feof($stream)) {
                $buffer = fread($stream, self::CHUNK_BYTES);

                if ($buffer === false || ($buffer === '' && !feof($stream))) {
                    throw new RuntimeException('Content stream made no progress.');
                }
                if ($buffer === '') {
                    break;
                }

                hash_update($hash, $buffer);
                $size += strlen($buffer);
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $written = fwrite($target, substr($buffer, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to stage content.');
                    }
                    $offset += $written;
                }
            }

            fclose($target);
        } catch (Throwable $exception) {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($path);

            throw $exception;
        }

        return new StagedTempObject(
            path: $path,
            sha256: hash_final($hash),
            sizeBytes: $size,
        );
    }

    public function promote(StagedObject $staged, string $key, ?string $disk = null): void
    {
        // Already at the canonical key (a dedup hit promoted by a concurrent
        // uploader, or an idempotent re-commit) — nothing to write.
        if ($this->driver->exists($key, $disk)) {
            return;
        }

        $stream = $staged->readStream();

        try {
            // If-None-Match: * — a 412/409 means we were overtaken; the driver
            // returns false and we treat that as "already there", not fatal.
            $written = $this->driver->put(
                key: $key,
                stream: $stream,
                disk: $disk,
                ifNoneMatch: true,
                options: ['ChecksumSHA256' => $this->base64Sha256($staged->sha256())],
            );

            // False is also the answer of a lost conditional create, where the object is there: only a missing
            // object after it is a failed write, and a caller must not go on as if the bytes had landed.
            if (!$written && !$this->exists($key, $disk)) {
                throw new RuntimeException("Unable to write the object [{$key}]: the storage driver reported a failed write.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return resource|null
     */
    public function get(string $key, ?string $disk = null): mixed
    {
        return $this->driver->get($key, $disk);
    }

    public function exists(string $key, ?string $disk = null): bool
    {
        return $this->driver->exists($key, $disk);
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $this->driver->delete($key, $disk);
    }

    public function lastModified(string $key, ?string $disk = null): ?int
    {
        // The listing capability lives on the DRIVER, not the base StorageDriver
        // contract; a non-listable driver cannot report age, so we degrade to null
        // (age unknown ⇒ the orphan walk skips rather than deletes).
        if (!$this->driver instanceof ListableStorageDriver) {
            return null;
        }

        return $this->driver->lastModified($key, $disk);
    }

    /**
     * @return iterable<string>
     */
    public function listKeys(?string $disk = null): iterable
    {
        // Only delegate when the underlying driver can actually enumerate the disk;
        // otherwise yield nothing so this store reports no objects rather than
        // calling a method the base StorageDriver contract no longer carries.
        if (!$this->driver instanceof ListableStorageDriver) {
            return;
        }

        yield from $this->driver->listContents($disk);
    }

    public function keyFor(BlobScope $scope, string $sha256): string
    {
        $segment = $scope->segment();
        $a = substr($sha256, 0, 2);

        return "{$segment}/{$a}/{$sha256}";
    }

    /**
     * S3 expects the checksum as base64 of the raw digest, not the hex string.
     */
    private function base64Sha256(string $hexSha256): string
    {
        $raw = hex2bin($hexSha256);

        return $raw === false ? '' : base64_encode($raw);
    }

    private function newTempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vaulter_cas_');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a staging temp file.');
        }

        return $path;
    }
}
