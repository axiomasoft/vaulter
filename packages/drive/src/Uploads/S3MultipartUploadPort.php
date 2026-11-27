<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Aws\Exception\AwsException;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Validation\ValidationException;
use Packman\Attributes\Internal;
use RuntimeException;
use Vaulter\Drive\Contracts\Uploads\MultipartUploadPort;
use Vaulter\Media\Cas\FlysystemStorageDriver;

#[Internal]
final readonly class S3MultipartUploadPort implements MultipartUploadPort
{
    public function __construct(private FlysystemStorageDriver $storage) {}

    public function enabled(?string $disk): bool
    {
        return ($this->storage->s3Disk($disk)?->getConfig()['vaulter_direct_multipart'] ?? false) === true;
    }

    public function initiate(?string $disk, string $key): string
    {
        $s3 = $this->disk($disk);
        $client = $s3->getClient();
        $command = $client->getCommand('CreateMultipartUpload', $this->storage->s3Address($s3, $key) + FlysystemStorageDriver::requestOptions());
        if ($this->storage->isR2($s3)) {
            $this->storage->omitPartChecksums($command);
        }
        $id = $client->execute($command)['UploadId'];

        return is_string($id) && $id !== '' ? $id : throw new RuntimeException('S3 did not return a multipart upload ID.');
    }

    public function presignPart(?string $disk, string $key, string $uploadId, int $part, int $size, DateTimeInterface $expires): string
    {
        $s3 = $this->disk($disk);
        $client = $s3->getClient();
        $command = $client->getCommand('UploadPart', $this->storage->s3Address($s3, $key) + ['UploadId' => $uploadId, 'PartNumber' => $part, 'ContentLength' => $size]);
        if ($this->storage->isR2($s3)) {
            $this->storage->omitPartChecksums($command);
        }

        return (string) $client->createPresignedRequest($command, $expires)->getUri();
    }

    public function complete(?string $disk, string $key, string $uploadId, int $total, int $partSize): array
    {
        $s3 = $this->disk($disk);
        $client = $s3->getClient();
        $address = $this->storage->s3Address($s3, $key);
        $parts = [];
        try {
            $marker = null;
            do {
                $pagination = $marker === null ? [] : ['PartNumberMarker' => $marker];
                $listed = $client->execute($client->getCommand('ListParts', $address + ['UploadId' => $uploadId] + $pagination + FlysystemStorageDriver::requestOptions()));
                $items = $listed['Parts'] ?? [];
                if (!is_array($items)) {
                    throw new RuntimeException('S3 returned an invalid parts list.');
                }
                foreach ($items as $item) {
                    $number = count($parts) + 1;
                    $expected = min($partSize, $total - ($number - 1) * $partSize);
                    if (!is_array($item) || ($item['PartNumber'] ?? null) !== $number || $expected <= 0
                        || ($item['Size'] ?? null) !== $expected || !is_string($item['ETag'] ?? null) || $item['ETag'] === '') {
                        throw $this->invalidParts();
                    }
                    $parts[] = ['PartNumber' => $number, 'ETag' => $item['ETag']];
                }
                $next = ($listed['IsTruncated'] ?? false) === true ? $listed['NextPartNumberMarker'] : null;
                if ($next !== null && (!is_numeric($next) || (int) $next <= (int) $marker)) {
                    throw new RuntimeException('S3 returned an invalid multipart page marker.');
                }
                $marker = $next === null ? null : (string) $next;
            } while ($marker !== null);
            if (count($parts) !== intdiv($total - 1, $partSize) + 1) {
                throw $this->invalidParts();
            }

            $command = $client->getCommand('CompleteMultipartUpload', $address + [
                'UploadId' => $uploadId, 'IfNoneMatch' => '*', 'MultipartUpload' => ['Parts' => $parts],
            ] + FlysystemStorageDriver::requestOptions());

            return $this->storage->objectIdentity($client->execute($command));
        } catch (AwsException $exception) {
            // Complete may have landed before a timeout or a database rollback. Only the server can write this key.
            if ($exception->getAwsErrorCode() === 'NoSuchUpload' || $exception->getStatusCode() === 412) {
                $head = $client->execute($client->getCommand('HeadObject', $address + FlysystemStorageDriver::requestOptions()));
                if ($head['ContentLength'] !== $total) {
                    throw $this->invalidParts();
                }

                return $this->storage->objectIdentity($head);
            }

            throw $exception;
        }
    }

    public function open(?string $disk, string $key, array $identity): mixed
    {
        return $this->storage->readObject($key, $disk, $identity['version'], $identity['etag']);
    }

    public function discard(?string $disk, string $key, ?string $uploadId, ?array $identity): void
    {
        $s3 = $this->disk($disk);
        $client = $s3->getClient();
        $address = $this->storage->s3Address($s3, $key);
        if ($uploadId !== null) {
            try {
                $client->execute($client->getCommand('AbortMultipartUpload', $address + ['UploadId' => $uploadId] + FlysystemStorageDriver::requestOptions()));
            } catch (AwsException $exception) {
                if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $exception;
                }
            }
            $identity ??= $this->storage->identify($key, $disk);
        }
        if ($identity !== null) {
            $guard = ['VersionId' => $identity['version']];
            if ($identity['version'] === $identity['etag']) {
                // The R2 quarantine key is unique to this session and only the server can complete it.
                // R2 does not advertise conditional DeleteObject, so check the ETag before removing it.
                if ($this->storage->isR2($s3)) {
                    $current = $this->storage->identify($key, $disk);
                    if ($current === null) {
                        return;
                    }
                    if ($current !== $identity) {
                        throw new RuntimeException('The quarantine object changed before cleanup.');
                    }
                    $guard = [];
                } else {
                    $guard = ['IfMatch' => $identity['etag']];
                }
            }
            $client->execute($client->getCommand('DeleteObject', $address + $guard + FlysystemStorageDriver::requestOptions()));
        }
    }

    private function disk(?string $disk): AwsS3V3Adapter
    {
        return $this->storage->s3Disk($disk) ?? throw new RuntimeException('The direct multipart disk is unavailable.');
    }

    private function invalidParts(): ValidationException
    {
        return ValidationException::withMessages(['parts' => 'S3 does not have the complete set of parts with the expected sizes.']);
    }
}
