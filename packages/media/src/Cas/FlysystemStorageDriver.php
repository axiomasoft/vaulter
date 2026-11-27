<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\ResultInterface;
use Aws\S3\MultipartUploader;
use Closure;
use DateTimeInterface;
use Fiber;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Packman\Attributes\Internal;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\ListableStorageDriver;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use WeakMap;

use function Aws\detach_on_close_stream;

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
    /** @var (Closure():float)|null */
    private static ?Closure $s3Budget = null;

    /** @var WeakMap<object, Closure():float>|null */
    private static ?WeakMap $fiberBudgets = null;

    public function __construct(
        private readonly MediaOptions $mediaConfig,
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
        if ($ifNoneMatch && $this->s3Disk($disk) !== null) {
            return $this->writeObject($key, $stream, $disk, true, $options) !== null;
        }

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

    public function s3Disk(?string $disk): ?AwsS3V3Adapter
    {
        $filesystem = $this->disk($disk);

        return $filesystem instanceof AwsS3V3Adapter ? $filesystem : null;
    }

    public function supportsEncryptedReads(?string $disk): bool
    {
        $filesystem = $this->disk($disk);

        return $filesystem instanceof AwsS3V3Adapter
            || ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter);
    }

    /** @return array{Bucket:string, Key:string} */
    public function s3Address(AwsS3V3Adapter $disk, string $key): array
    {
        $bucket = $disk->getConfig()['bucket'] ?? null;

        return ['Bucket' => is_string($bucket) ? $bucket : throw new RuntimeException('S3 disk requires a bucket.'), 'Key' => $disk->path($key)];
    }

    public function isR2(AwsS3V3Adapter $disk): bool
    {
        return ($disk->getConfig()['vaulter_s3_profile'] ?? 's3') === 'r2';
    }

    /**
     * @template T
     *
     * @param  Closure():float $remaining
     * @param  Closure():T     $operation
     * @return T
     */
    public static function withinS3Budget(Closure $remaining, Closure $operation): mixed
    {
        $fiber = Fiber::getCurrent();
        $previous = self::budget();
        if ($fiber === null) {
            self::$s3Budget = $remaining;
        } else {
            $budgets = self::$fiberBudgets ??= new WeakMap;
            $budgets[$fiber] = $remaining;
        }
        try {
            return $operation();
        } finally {
            if ($fiber === null) {
                self::$s3Budget = $previous;
            } else {
                if ($previous === null) {
                    unset($budgets[$fiber]);
                } else {
                    $budgets[$fiber] = $previous;
                }
            }
        }
    }

    /** @return (Closure():float)|null */
    private static function budget(): ?Closure
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? self::$s3Budget : (self::$fiberBudgets[$fiber] ?? null);
    }

    /** @return array<string, mixed> */
    public static function requestOptions(bool $stream = false): array
    {
        $remaining = self::budget();
        if ($remaining === null) {
            return $stream ? ['@http' => ['stream' => true, 'decode_content' => false]] : [];
        }
        $seconds = $remaining();
        if ($seconds <= 0) {
            throw new RuntimeException('The S3 operation deadline expired.');
        }

        // cURL downloads to a spooled stream and applies the overall timeout to the transfer.
        return ['@retries' => 0, '@http' => ['timeout' => $seconds, 'connect_timeout' => $seconds, 'decode_content' => false]];
    }

    private static function applyBudget(CommandInterface $command): void
    {
        foreach (self::requestOptions() as $key => $value) {
            $command[$key] = $value;
        }
    }

    /**
     * @param  resource                               $stream
     * @param  array<string, mixed>                   $options
     * @return array{version:string,etag:string}|null null denotes a conditional-write conflict
     */
    public function writeObject(string $key, mixed $stream, ?string $disk, bool $conditional, array $options = []): ?array
    {
        $filesystem = $this->s3Disk($disk) ?? throw new RuntimeException('Versioned writes require an S3 disk.');
        $address = $this->s3Address($filesystem, $key);
        $client = $filesystem->getClient();
        $guard = $conditional ? ['IfNoneMatch' => '*'] : [];
        $size = fstat($stream)['size'] ?? null;
        $r2 = $this->isR2($filesystem);

        try {
            if ($size !== null && $size < 16_777_216) {
                $checksum = $r2 ? [] : array_intersect_key($options, ['ChecksumSHA256' => true]);
                $body = detach_on_close_stream(Utils::streamFor($stream));
                $command = $client->getCommand('PutObject', $address + $guard + $checksum + ['Body' => $body, 'ContentLength' => $size] + self::requestOptions());
                if ($r2) {
                    $this->omitPartChecksums($command);
                }
                $result = $client->execute($command);
            } else {
                $uploader = new MultipartUploader($client, $stream, [
                    'bucket' => $address['Bucket'],
                    'key' => $address['Key'],
                    'concurrency' => 1,
                    'before_initiate' => function (CommandInterface $command) use ($r2): void {
                        self::applyBudget($command);
                        unset($command['ACL']);
                        if ($r2) {
                            $this->omitPartChecksums($command);
                        }
                    },
                    'before_upload' => function (CommandInterface $command) use ($r2): void {
                        self::applyBudget($command);
                        if ($r2) {
                            $this->omitPartChecksums($command);
                        }
                    },
                    'before_complete' => static function (CommandInterface $command) use ($guard): void {
                        self::applyBudget($command);
                        foreach ($guard as $name => $value) {
                            $command[$name] = $value;
                        }
                    },
                ]);
                $result = $uploader->upload();
            }
        } catch (MultipartUploadException $exception) {
            $id = $exception->getState()->getId();
            if (isset($id['UploadId'])) {
                try {
                    $client->execute($client->getCommand('AbortMultipartUpload', $id + ['@retries' => 0, '@http' => ['timeout' => 2, 'connect_timeout' => 2]]));
                } catch (AwsException $cleanup) {
                    if ($cleanup->getAwsErrorCode() !== 'NoSuchUpload') {
                        report($cleanup);
                    }
                }
            }
            $failure = $exception->getPrevious();
            if ($conditional && $failure instanceof AwsException && in_array($failure->getStatusCode(), [409, 412], true)) {
                return null;
            }

            throw $exception;
        } catch (AwsException $exception) {
            if ($conditional && in_array($exception->getStatusCode(), [409, 412], true)) {
                return null;
            }

            throw $exception;
        }

        return $this->objectIdentity($result);
    }

    public function omitPartChecksums(CommandInterface $command): void
    {
        unset($command['ChecksumSHA256'], $command['ChecksumAlgorithm']);
        $command->getHandlerList()->remove('s3.checksum');
    }

    /** @return array{version:string,etag:string} */
    public function objectIdentity(ResultInterface $result): array
    {
        $etag = $result['ETag'];
        if (!is_string($etag) || $etag === '') {
            throw new RuntimeException('S3 did not return an object ETag.');
        }
        $version = $result['VersionId'];

        return ['version' => is_string($version) && $version !== '' && $version !== 'null' ? $version : $etag, 'etag' => $etag];
    }

    /** @return array{version:string,etag:string}|null */
    public function identify(string $key, ?string $disk, ?string $version = null, ?string $etag = null): ?array
    {
        $filesystem = $this->s3Disk($disk);
        if ($filesystem === null) {
            return null;
        }
        $client = $filesystem->getClient();
        try {
            $options = $this->s3Address($filesystem, $key) + $this->readConditions($version, $etag) + self::requestOptions();

            return $this->objectIdentity($client->execute($client->getCommand('HeadObject', $options)));
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /** @return resource|null */
    public function readObject(string $key, ?string $disk, ?string $version, ?string $etag, ?int $offset = null, ?int $length = null): mixed
    {
        $filesystem = $this->s3Disk($disk);
        if ($filesystem === null) {
            $stream = $this->get($key, $disk);
            if (is_resource($stream) && $offset !== null && fseek($stream, $offset) !== 0) {
                fclose($stream);
                throw new RuntimeException('The storage disk cannot seek to the encrypted segment.');
            }

            return $stream;
        }
        $client = $filesystem->getClient();
        $identity = $this->readConditions($version, $etag);
        $range = $offset === null || $length === null ? [] : ['Range' => 'bytes='.$offset.'-'.($offset + $length - 1)];
        $command = $client->getCommand('GetObject', $this->s3Address($filesystem, $key) + $identity + $range + self::requestOptions(stream: true));
        try {
            $body = $client->execute($command)['Body'];
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }
            throw $exception;
        }
        if (!$body instanceof StreamInterface) {
            throw new RuntimeException('S3 returned an invalid response body.');
        }

        $stream = $body->detach();

        return is_resource($stream) ? $stream : throw new RuntimeException('S3 did not return an object stream.');
    }

    /** @return array<string, string> */
    private function readConditions(?string $version, ?string $etag): array
    {
        return $version === null ? [] : ($version === $etag ? ['IfMatch' => $version] : ['VersionId' => $version]);
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
        } catch (Throwable $exception) {
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
        } catch (Throwable $exception) {
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
     * truncated-object guard still applies. Signed URLs are also available on local
     * disks and do not establish atomic writes; only the S3 adapter guarantees a PUT.
     */
    private function writesAtomically(Filesystem $filesystem): bool
    {
        return $filesystem instanceof AwsS3V3Adapter;
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
        if (self::budget() !== null && $this->s3Disk($disk) !== null) {
            return $this->identify($key, $disk) !== null;
        }

        return $this->disk($disk)->exists($key);
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $filesystem = $this->disk($disk);

        if ($filesystem->exists($key)) {
            $filesystem->delete($key);
        }
    }

    public function deleteTrackedObject(string $key, ?string $disk, string $version): void
    {
        $filesystem = $this->s3Disk($disk) ?? throw new RuntimeException('Version deletion requires an S3 disk.');
        $client = $filesystem->getClient();
        $address = $this->s3Address($filesystem, $key);
        // Leave a delete marker so previous versions cannot become the latest object.
        if ($this->identify($key, $disk) !== null) {
            $client->execute($client->getCommand('DeleteObject', $address + self::requestOptions()));
        }
        try {
            $arguments = $address + ['VersionId' => $version] + self::requestOptions();
            $client->execute($client->getCommand('DeleteObject', $arguments));
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
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
