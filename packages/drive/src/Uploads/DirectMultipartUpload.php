<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Closure;
use Illuminate\Validation\ValidationException;
use Packman\Attributes\Internal;
use RuntimeException;
use Throwable;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\DriveOptionsValidator;
use Vaulter\Drive\Contracts\Uploads\MultipartUploadPort;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\ContentScanner;

/** Persists the quarantine object and its scan receipt under the upload session's existing lock. */
#[Internal]
final readonly class DirectMultipartUpload
{
    /** @param Closure(): ContentScanner $resolveScanner */
    public function __construct(
        private MultipartUploadPort $port,
        private UploadSessionStoreRepository $sessions,
        private DriveOptions $options,
        private MediaOptions $media,
        private DriveOptionsValidator $validator,
        private Closure $resolveScanner,
    ) {}

    public function initialise(UploadSession $session): void
    {
        $disk = $session->storage()->first()->disk ?? $this->media->disk();
        if (!$this->port->enabled($disk)) {
            return;
        }
        $this->validator->validateScanWork($this->options, requireBroker: true);
        ($this->resolveScanner)();
        $total = $session->total_size_bytes;
        if ($total === null || $total < 1 || $total > 5_497_558_138_880) {
            throw ValidationException::withMessages(['total_size_bytes' => 'Direct multipart requires a declared positive object size supported by S3.']);
        }
        $partSize = max(5_242_880, $session->chunk_size ?? 0, intdiv($total - 1, 10_000) + 1);
        $limit = $this->options->uploads->resumable->maxChunkSize;
        if ($partSize > 5_368_709_120 || ($limit > 0 && min($total, $partSize) > $limit)) {
            throw ValidationException::withMessages(['total_size_bytes' => 'Object cannot be split into direct parts within the configured limit.']);
        }
        $session->forceFill(['chunk_size' => $partSize, 'parts' => ['mode' => 'direct', 'disk' => $disk, 'part_size' => $partSize]])->save();
    }

    public static function active(UploadSession $session): bool
    {
        return ($session->parts['mode'] ?? null) === 'direct';
    }

    /** @return array{part_no:int,size:int,url:string} */
    public function part(UploadSession $session, int $part): array
    {
        if (!$session->status->isOpen() || $session->expires_at === null || $session->expires_at <= now() || $this->identity($session) !== null) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }
        $partSize = $this->partSize($session);
        $total = $this->total($session);
        if ($part < 1 || $part > intdiv($total - 1, $partSize) + 1) {
            throw ValidationException::withMessages(['part_no' => 'Part number is outside this upload’s declared size.']);
        }
        $size = min($partSize, $total - ($part - 1) * $partSize);
        $id = $this->uploadId($session);
        $created = $id === null;
        $disk = $this->sessionDisk($session);
        $key = $this->key($session);
        $id ??= $this->port->initiate($disk, $key);
        try {
            $expires = now()->addMinutes(10)->min($session->expires_at);
            $url = $this->port->presignPart($disk, $key, $id, $part, $size, $expires);
            if ($created) {
                $this->save($session, ['upload_id' => $id]);
            }
        } catch (Throwable $exception) {
            if ($created) {
                try {
                    $this->port->discard($disk, $key, $id, null);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $exception;
        }

        return ['part_no' => $part, 'size' => $size, 'url' => $url];
    }

    /** @return array{version:string,etag:string} */
    public function complete(UploadSession $session): array
    {
        if (!$session->status->isOpen() || $session->expires_at <= now()) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }
        $identity = $this->identity($session);
        if ($identity !== null) {
            return $identity;
        }
        $id = $this->uploadId($session) ?? throw ValidationException::withMessages(['parts' => 'No direct parts were requested.']);
        $size = $this->total($session);
        $identity = $this->port->complete($this->sessionDisk($session), $this->key($session), $id, $size, $this->partSize($session));
        $this->save($session, $identity);
        $this->sessions->advanceReceived($session, $size);

        return $identity;
    }

    /** @return array{version:string,etag:string}|null */
    public function identity(UploadSession $session): ?array
    {
        $parts = $session->parts ?? [];
        $version = $parts['version'] ?? null;
        $etag = $parts['etag'] ?? null;

        return is_string($version) && is_string($etag) ? ['version' => $version, 'etag' => $etag] : null;
    }

    public function rejected(UploadSession $session): bool
    {
        return ($session->parts['scan'] ?? null) === 'rejected';
    }

    public function cleanDigest(UploadSession $session): ?string
    {
        $digest = $session->parts['scan_sha256'] ?? null;

        return ($session->parts['scan'] ?? null) === $this->policy() && is_string($digest) ? $digest : null;
    }

    public function settle(UploadSession $session, ?string $digest): void
    {
        $this->save($session, ['scan' => $digest === null ? 'rejected' : $this->policy(), 'scan_sha256' => $digest]);
    }

    public function reserveScanDispatch(UploadSession $session): bool
    {
        $next = $session->parts['scan_dispatch_after'] ?? null;
        $now = now()->getTimestamp();
        if (is_int($next) && $next > $now) {
            return false;
        }
        $this->save($session, ['scan_dispatch_after' => $now + $this->options->uploads->scan->leaseSeconds]);

        return true;
    }

    public function policy(): string
    {
        $spec = $this->options->scan->globalSpec();

        return hash('sha256', json_encode([$spec->scannerKey, $spec->scannerVersion, $spec->policyVersion], JSON_THROW_ON_ERROR));
    }

    /** @param array{version:string,etag:string} $identity @return resource|null */
    public function open(UploadSession $session, array $identity): mixed
    {
        return $this->port->open($this->sessionDisk($session), $this->key($session), $identity);
    }

    public function discard(UploadSession $session): void
    {
        $identity = $this->identity($session);
        $id = $identity === null ? $this->uploadId($session) : null;
        if ($id !== null || $identity !== null) {
            $this->port->discard($this->sessionDisk($session), $this->key($session), $id, $identity);
        }
    }

    private function key(UploadSession $session): string
    {
        return 'quarantine/'.rawurlencode((string) ($session->tenant_id ?? '_')).'/'.$session->upload_id;
    }

    private function sessionDisk(UploadSession $session): string
    {
        $disk = $session->parts['disk'] ?? null;

        return is_string($disk) ? $disk : throw new RuntimeException('Direct multipart is missing its quarantine disk.');
    }

    private function total(UploadSession $session): int
    {
        return $session->total_size_bytes ?? throw new RuntimeException('Direct multipart is missing its declared size.');
    }

    private function partSize(UploadSession $session): int
    {
        $partSize = $session->parts['part_size'] ?? null;

        return is_int($partSize) && $partSize > 0 ? $partSize : throw new RuntimeException('Direct multipart is missing its part size.');
    }

    private function uploadId(UploadSession $session): ?string
    {
        $id = $session->parts['upload_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /** @param array<string,mixed> $fields */
    private function save(UploadSession $session, array $fields): void
    {
        $session->forceFill(['parts' => array_replace($session->parts ?? [], $fields)])->save();
    }
}
