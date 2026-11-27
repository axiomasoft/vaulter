<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Closure;
use Packman\Attributes\Internal;
use RuntimeException;
use Throwable;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\ListableContentStore;
use Vaulter\Media\Contracts\Cas\ListableStorageDriver;
use Vaulter\Media\Contracts\Cas\StagedObject;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Encryption\EncryptedReadStream;
use Vaulter\Media\Encryption\InvalidEncryptedSegment;
use Vaulter\Media\Encryption\KeyManagementPort;
use Vaulter\Media\Encryption\SegmentedSecretStreamCipher;
use Vaulter\Media\Models\Cas\Blob;
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

    private const string ENCRYPTED_FORMAT = "VAULTER-SECRETSTREAM-1\0";

    /** @param (Closure(): ?KeyManagementPort)|null $resolveKeys */
    public function __construct(
        private StorageDriver $driver,
        private ?Closure $resolveKeys = null,
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
        $this->promoteObject($staged, $key, $disk);
    }

    /** @return array{adopted:bool,identity:array{version:string,etag:string}|null} */
    private function promoteObject(StagedObject $staged, string $key, ?string $disk): array
    {
        if ($this->driver instanceof FlysystemStorageDriver && $this->driver->s3Disk($disk) !== null) {
            $stream = $staged->readStream();
            try {
                $identity = $this->driver->writeObject($key, $stream, $disk, true, ['ChecksumSHA256' => $this->base64Sha256($staged->sha256())]);

                return [
                    'adopted' => $identity === null,
                    'identity' => $identity ?? $this->driver->identify($key, $disk) ?? throw new RuntimeException("The conditional write of [{$key}] did not land."),
                ];
            } finally {
                fclose($stream);
            }
        }

        // Already at the canonical key (a dedup hit promoted by a concurrent
        // uploader, or an idempotent re-commit) — nothing to write.
        if ($this->driver->exists($key, $disk)) {
            return ['adopted' => true, 'identity' => null];
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

        return ['adopted' => !$written, 'identity' => null];
    }

    /** Returns whether an existing object was adopted after a conditional conflict. */
    public function promoteBlob(Blob $blob, StagedObject $staged, ?string $tenant, bool $rewrite = false, bool $created = false): bool
    {
        $keys = $created || $blob->wrapped_dek !== null ? $this->resolveKeys?->__invoke() : null;
        if ($blob->wrapped_dek === null && $keys === null) {
            $promotion = $this->promoteObject($staged, $blob->storage_key, $blob->disk);
            $this->recordVersion($blob, $promotion['identity']);

            return $promotion['adopted'];
        }

        if (!$this->driver instanceof FlysystemStorageDriver || !$this->driver->supportsEncryptedReads($blob->disk)) {
            throw new RuntimeException('Segmented encryption requires the default range-capable storage driver.');
        }
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Segmented encryption requires PHP Sodium.');
        }
        $keys ??= throw new RuntimeException('Segmented encryption requires a key provider.');
        $stream = $staged->readStream();
        $ciphertext = tmpfile();
        $dek = null;
        try {
            if ($ciphertext === false) {
                throw new RuntimeException('Unable to allocate encrypted staging storage.');
            }
            $envelope = $blob->wrapped_dek;
            if ($envelope === null) {
                $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
                $wrapped = $keys->wrap($dek);
                $keyId = $wrapped['key_id'];
                $envelope = json_encode(['key_id' => $keyId, 'tenant' => $tenant, 'wrapped' => base64_encode($wrapped['wrapped_dek'])], JSON_THROW_ON_ERROR);
            } else {
                $keyId = $blob->encryption_key_id ?? throw new RuntimeException('Missing encryption key ID.');
                $dek = $this->unwrap($blob, $envelope, $keys);
            }
            $prefix = $this->prefix($envelope);
            new SegmentedSecretStreamCipher()->encrypt($stream, $ciphertext, $prefix, $blob->size_bytes, $this->aad($blob, $envelope), $dek);
            rewind($ciphertext);
            $identity = null;
            if ($this->driver->s3Disk($blob->disk) !== null) {
                $identity = $this->driver->writeObject($blob->storage_key, $ciphertext, $blob->disk, !$rewrite);
                $written = $identity !== null;
            } else {
                $written = $this->driver->put($blob->storage_key, $ciphertext, $blob->disk, !$rewrite);
            }
            if (!$written) {
                if ($rewrite) {
                    throw new RuntimeException('Unable to replace the encrypted object.');
                }
                $identity = $this->driver->identify($blob->storage_key, $blob->disk);
                [$keyId, $envelope] = $this->recoverEnvelope($blob, $identity, $tenant);
            }
            $blob->forceFill(['wrapped_dek' => $envelope, 'encryption_key_id' => $keyId]);
            $this->recordVersion($blob, $identity);

            return !$written;
        } finally {
            if ($dek !== null) {
                sodium_memzero($dek);
            }
            fclose($stream);
            if (is_resource($ciphertext)) {
                fclose($ciphertext);
            }
        }
    }

    /** @param array{version:string,etag:string}|null $identity */
    public function recordVersion(Blob $blob, ?array $identity = null): void
    {
        if ($identity === null && $this->driver instanceof FlysystemStorageDriver) {
            $identity = $this->driver->identify($blob->storage_key, $blob->disk);
        }
        if ($identity !== null) {
            $blob->forceFill(['storage_version' => $identity['version'], 'etag' => $identity['etag']]);
        }
        if ($blob->isDirty(['wrapped_dek', 'encryption_key_id', 'storage_version', 'etag'])) {
            $blob->save();
        }
    }

    /** @return resource|null */
    public function readBlob(Blob $blob): mixed
    {
        if (!$this->driver instanceof FlysystemStorageDriver) {
            if ($blob->encryption_key_id !== null) {
                throw new RuntimeException('Encrypted objects require the default range-capable storage driver.');
            }

            return $this->get($blob->storage_key, $blob->disk);
        }
        $envelope = $blob->wrapped_dek;
        if ($envelope === null) {
            if ($blob->encryption_key_id !== null) {
                throw new RuntimeException('The encrypted object is missing its key envelope.');
            }

            return $blob->storage_version === null
                ? $this->get($blob->storage_key, $blob->disk)
                : $this->driver->readObject($blob->storage_key, $blob->disk, $blob->storage_version, $blob->etag);
        }
        $present = $this->driver->s3Disk($blob->disk) === null
            ? $this->driver->exists($blob->storage_key, $blob->disk)
            : $this->driver->identify($blob->storage_key, $blob->disk, $blob->storage_version, $blob->etag) !== null;
        if (!$present) {
            return null;
        }
        $prefixLength = strlen($this->prefix($envelope));
        $aad = $this->aad($blob, $envelope);
        $keys = $this->resolveKeys?->__invoke() ?? throw new RuntimeException('Segmented encryption requires a key provider.');
        $dek = $this->unwrap($blob, $envelope, $keys);
        $clear = static function () use (&$dek): void {
            if ($dek !== null) {
                sodium_memzero($dek);
            }
        };
        try {
            $driver = $this->driver;
            $cipher = new SegmentedSecretStreamCipher;

            return EncryptedReadStream::open($blob->size_bytes, static function (int $index) use ($driver, $blob, $cipher, $prefixLength, $aad, &$dek): string {
                $segmentSize = SegmentedSecretStreamCipher::SEGMENT_BYTES;
                $length = min($segmentSize, $blob->size_bytes - $index * $segmentSize);
                $recordLength = $length + SegmentedSecretStreamCipher::OVERHEAD;
                $offset = $prefixLength + $index * ($segmentSize + SegmentedSecretStreamCipher::OVERHEAD);
                $stream = $driver->readObject($blob->storage_key, $blob->disk, $blob->storage_version, $blob->etag, $offset, $recordLength);
                if (!is_resource($stream)) {
                    throw new InvalidEncryptedSegment('Encrypted segment authentication failed: object is missing.');
                }
                try {
                    $bytes = stream_get_contents($stream, $recordLength);
                    if ($bytes === false || $dek === null) {
                        throw new RuntimeException('Unable to read the encrypted segment.');
                    }

                    return $cipher->decrypt($bytes, $length, $aad, $index, $dek);
                } finally {
                    fclose($stream);
                }
            }, $clear);
        } catch (Throwable $exception) {
            $clear();
            throw $exception;
        }
    }

    /** @param array{version:string,etag:string}|null $identity
     * @return array{int|string,string}
     */
    private function recoverEnvelope(Blob $blob, ?array $identity, ?string $tenant): array
    {
        $stream = $this->driver instanceof FlysystemStorageDriver
            ? $this->driver->readObject($blob->storage_key, $blob->disk, $identity['version'] ?? null, $identity['etag'] ?? null) : null;
        if (!is_resource($stream)) {
            throw new RuntimeException('The conflicting encrypted object could not be read.');
        }
        try {
            $magic = stream_get_contents($stream, strlen(self::ENCRYPTED_FORMAT));
            $length = stream_get_contents($stream, 2);
            if ($magic !== self::ENCRYPTED_FORMAT || $length === false || strlen($length) !== 2) {
                throw new RuntimeException('The conflicting object has no encryption envelope.');
            }
            $count = ord($length[0]) * 256 + ord($length[1]);
            $envelope = $count > 0 ? stream_get_contents($stream, $count) : false;
            if ($envelope === false || strlen($envelope) !== $count) {
                throw new RuntimeException('The conflicting object has a truncated encryption envelope.');
            }
            $data = $this->envelope($envelope);
            if ($data['tenant'] !== $tenant) {
                throw new RuntimeException('The conflicting object belongs to a different encryption tenant.');
            }

            return [$data['key_id'], $envelope];
        } finally {
            fclose($stream);
        }
    }

    private function unwrap(Blob $blob, string $envelope, KeyManagementPort $keys): string
    {
        $data = $this->envelope($envelope);
        if ((string) $blob->encryption_key_id !== (string) $data['key_id']) {
            throw new RuntimeException('Encryption key ID differs from its envelope.');
        }
        $wrapped = base64_decode($data['wrapped'], true);
        if ($wrapped === false || $wrapped === '') {
            throw new RuntimeException('Invalid wrapped data key.');
        }

        return $keys->unwrap($data['key_id'], $wrapped);
    }

    /** @return array{key_id:int|string,tenant:?string,wrapped:string} */
    private function envelope(string $envelope): array
    {
        $data = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || (!is_int($data['key_id'] ?? null) && !is_string($data['key_id'] ?? null))
            || !array_key_exists('tenant', $data) || ($data['tenant'] !== null && !is_string($data['tenant'])) || !is_string($data['wrapped'] ?? null)) {
            throw new RuntimeException('Invalid encryption envelope.');
        }

        return ['key_id' => $data['key_id'], 'tenant' => $data['tenant'], 'wrapped' => $data['wrapped']];
    }

    private function prefix(string $envelope): string
    {
        $size = strlen($envelope);
        if ($size > 65_535) {
            throw new RuntimeException('The encryption envelope exceeds the storage format limit.');
        }

        return self::ENCRYPTED_FORMAT.pack('n', $size).$envelope;
    }

    private function aad(Blob $blob, string $envelope): string
    {
        return json_encode([$this->envelope($envelope)['tenant'], $blob->sha256, $blob->scope_key, $blob->size_bytes, $envelope], JSON_THROW_ON_ERROR);
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

    public function deleteBlob(Blob $blob): void
    {
        if ($this->driver instanceof FlysystemStorageDriver && $blob->storage_version !== null && $blob->storage_version !== $blob->etag
            && $this->driver->s3Disk($blob->disk) !== null) {
            $this->driver->deleteTrackedObject($blob->storage_key, $blob->disk, $blob->storage_version);

            return;
        }
        $this->delete($blob->storage_key, $blob->disk);
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
