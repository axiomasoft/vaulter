<?php

declare(strict_types=1);

namespace Vaulter\Cas;

use RuntimeException;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Contracts\Cas\StorageDriver;

/**
 * The default ContentStore (H.3): hash the LOCAL staged temp file, never the live
 * stream. An incoming stream is drained into a seekable temp file; the sha256 is
 * computed from those durable bytes (a lying client sha256 or an S3 multipart
 * ETag is irrelevant). Promotion writes the staged bytes to the canonical
 * scope-qualified key with a conditional create so a concurrent identical upload
 * cannot clobber, and on S3 attaches x-amz-checksum-sha256 for server-side
 * verification (config-aware; ignored by the local/sqlite test disk).
 */
final readonly class Sha256ContentStore implements ContentStore
{
    /**
     * Read buffer for streaming the upload into the staged temp file.
     */
    private const int CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly StorageDriver $driver,
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

        while (!feof($stream)) {
            $buffer = fread($stream, self::CHUNK_BYTES);

            if ($buffer === false) {
                break;
            }

            hash_update($hash, $buffer);
            $size += strlen($buffer);
            fwrite($target, $buffer);
        }

        fclose($target);

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
            // returns false and we treat that as "already there" (H.3), not fatal.
            $this->driver->put(
                key: $key,
                stream: $stream,
                disk: $disk,
                ifNoneMatch: true,
                options: ['ChecksumSHA256' => $this->base64Sha256($staged->sha256())],
            );
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

    public function keyFor(string $scope, string $sha256): string
    {
        // "<scope>/ab/cd/<sha256>" — the scope segment is what stops two dedup
        // scopes sharing one physical object (H.3 / D18′).
        $a = substr($sha256, 0, 2);
        $b = substr($sha256, 2, 2);

        return "{$scope}/{$a}/{$b}/{$sha256}";
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
