<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs\Uploads;

use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Packman\Attributes\Internal;
use RuntimeException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Drive\Uploads\DirectMultipartUpload;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Content\ClamAvContentScanner;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Tenancy\TenancyBypass;

/** Scan the completed quarantine object while it is still outside CAS. */
#[Internal]
final class ScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    /** @param array{version:string,etag:string} $identity */
    public function __construct(public string $uploadId, public int|string $driveId, public int|string|null $tenantId, public array $identity, public string $policy) {}

    /** @return list<RestoreTenantContext> */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->driveId, $this->tenantId)];
    }

    public function handle(
        UploadSessionReadRepository $read,
        UploadSessionStoreRepository $write,
        DirectMultipartUpload $direct,
        DriveOptions $options,
        ContentScanner $scanner,
        VaulterDatabase $database,
        TenancyBypass $bypass,
        TenancyBoundary $tenancy,
    ): void {
        $bypass->suspend(function () use ($read, $write, $direct, $options, $scanner, $database, $tenancy): void {
            $tenancy->assertJobDrive($this->driveId, $this->tenantId);
            $session = $read->findByUploadId($this->uploadId);
            if ($session === null || !$this->pending($session, $direct)) {
                return;
            }
            $spec = $options->scan->globalSpec();
            $timeout = $options->uploads->scan->scannerTimeoutSeconds;
            $until = hrtime(true) / 1_000_000_000 + $timeout;
            $deadline = new DateTimeImmutable()->modify('+'.$timeout.' seconds');
            // The clamd adapter checks the live version under the scan's own deadline.
            if ($scanner->key() !== $spec->scannerKey || (!$scanner instanceof ClamAvContentScanner && $scanner->version() !== $spec->scannerVersion)) {
                throw new RuntimeException('The quarantine scanner no longer matches the configured policy.');
            }
            $stream = FlysystemStorageDriver::withinS3Budget(static fn (): float => $until - hrtime(true) / 1_000_000_000, fn (): mixed => $direct->open($session, $this->identity));
            if (!is_resource($stream)) {
                throw new RuntimeException('The completed quarantine object is unavailable.');
            }
            try {
                $size = $session->total_size_bytes ?? throw new RuntimeException('The quarantine session has no declared size.');
                $hash = hash_init('sha256');
                $readBytes = hash_update_stream($hash, $stream, $size + 1);
                $sha256 = hash_final($hash);
                $digest = null;
                if ($readBytes === $size && ($session->expected_sha256 === null || hash_equals(strtolower($session->expected_sha256), $sha256))) {
                    if (!rewind($stream)) {
                        throw new RuntimeException('The completed quarantine object is unreadable.');
                    }
                    $result = $scanner->scan(new ScanInput($stream, $session->upload_id, $this->identity['version'], $this->driveId, $this->tenantId,
                        'quarantine:'.$session->upload_id, $sha256, $size, null, $spec->scannerVersion, $spec->policyVersion, $deadline));
                    if ($result->retryable || $deadline <= new DateTimeImmutable) {
                        throw new RuntimeException('Quarantine scan failed temporarily.');
                    }
                    $digest = $result->verdict === ScanVerdict::Clean ? $sha256 : null;
                }
            } finally {
                fclose($stream);
            }
            $database->write(fn () => $database->transaction(function () use ($write, $direct, $session, $digest, $database): void {
                $locked = $write->acquireForPatch($session);
                if ($this->pending($locked, $direct)) {
                    $direct->settle($locked, $digest);
                    if ($digest === null) {
                        $database->afterCommit(static function () use ($direct, $locked): void {
                            FlysystemStorageDriver::withinS3Budget(static fn (): float => 5.0, fn () => $direct->discard($locked));
                        });
                    }
                }
            }));
        });
    }

    private function pending(UploadSession $session, DirectMultipartUpload $direct): bool
    {
        return (string) $session->drive_id === (string) $this->driveId
            && TenancyBoundary::normalize($session->tenant_id) === TenancyBoundary::normalize($this->tenantId)
            && $session->status->isOpen() && $session->expires_at > now()
            && DirectMultipartUpload::active($session) && $direct->identity($session) === $this->identity
            && $this->policy === $direct->policy() && !$direct->rejected($session) && $direct->cleanDigest($session) === null;
    }
}
