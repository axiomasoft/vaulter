<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs\Uploads;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Content\FailScanAttemptAction;
use Vaulter\Drive\Actions\Content\FenceScanAttemptAction;
use Vaulter\Drive\Actions\Content\SettleScanWorkAction;
use Vaulter\Drive\Configuration\ScanOptions;
use Vaulter\Drive\Content\PrivateScanReader;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Content\VerifiedScanStream;
use Vaulter\Drive\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Tenancy\TenancyBypass;

#[Internal]
final class ScanFileRevision implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int|string $obligationId, public int $generation,
        public int|string $driveId, public int|string|null $tenantId) {}

    /** @return list<RestoreTenantContext> */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->driveId, $this->tenantId)];
    }

    public function handle(ScanObligationRepository $work, ClaimScanWorkAction $claim, FenceScanAttemptAction $fence, FailScanAttemptAction $fail, SettleScanWorkAction $settle, PrivateScanReader $reader, ScanSnapshot $snapshots,
        ScanOptions $config, VaulterDatabase $database): void
    {
        if (app(TenancyOptions::class)->enabled) {
            app(TenancyBoundary::class)->assertJobDrive($this->driveId, $this->tenantId);
        }
        $database->write(fn () => app(TenancyBypass::class)->suspend(fn () => $this->execute($work, $claim, $fence, $fail, $settle, $reader, $snapshots, $config)));
    }

    private function execute(ScanObligationRepository $work, ClaimScanWorkAction $claim, FenceScanAttemptAction $fence, FailScanAttemptAction $fail, SettleScanWorkAction $settle, PrivateScanReader $reader, ScanSnapshot $snapshots, ScanOptions $config): void
    {
        // The queued scope is a fence, never a permission to derive a different tenant.
        $row = $work->find($this->obligationId);
        if ($row === null || (string) $row->drive_id !== (string) $this->driveId
            || TenancyBoundary::normalize($row->tenant_id) !== TenancyBoundary::normalize($this->tenantId)) {
            return;
        }
        $scanner = app(ContentScanner::class);
        if ($scanner->key() !== $row->scanner_key || $scanner->version() !== $row->scanner_version
            || $scanner->key() !== $config->globalSpec()->scannerKey
            || $scanner->version() !== $config->globalSpec()->scannerVersion) {
            throw new InvalidConfigurationException('Configured scanner identity differs from durable work.');
        }
        $attempt = $claim->execute($this->obligationId, $this->generation, $work->now());
        if ($attempt === null || $attempt->blobId === null) {
            return;
        }
        // Inspect the authoritative chain before opening bytes. A broken edge is
        // deterministic failure; it must not turn into an endless IO retry.
        try {
            $revision = $fence->execute($attempt);
            if ($revision === null) {
                $fail->execute($attempt, 'content_identity_mismatch');

                return;
            }
            $snapshot = $snapshots->load($revision);
            $identity = $snapshot['identity'];
            if ($identity === null || $identity->fingerprint() !== $attempt->contentIdentity) {
                $fail->execute($attempt, 'content_identity_mismatch');

                return;
            }
        } catch (LogicException) {
            $fail->execute($attempt, 'content_identity_mismatch');

            return;
        }
        $stream = null;
        try {
            try {
                $stream = $reader->open($attempt);
            } catch (LogicException) {
                $fail->execute($attempt, 'content_identity_mismatch');

                return;
            }
            if (!is_resource($stream)) {
                if ($fence->execute($attempt) === null) {
                    $fail->execute($attempt, 'content_identity_mismatch');

                    return;
                }
                $result = new ScanResult(ScanVerdict::Failed, 'transient_io', true);
            } else {
                if ($scanner->key() !== $attempt->spec->scannerKey || $scanner->version() !== $attempt->spec->scannerVersion) {
                    throw new InvalidConfigurationException('Scanner identity changed before byte IO.');
                }
                // The row hash is only a claim. The verdict has to follow the bytes just read.
                $verified = VerifiedScanStream::copy($stream);
                fclose($stream);
                $stream = $verified?->stream;
                if (!$verified instanceof VerifiedScanStream) {
                    $result = new ScanResult(ScanVerdict::Failed, 'transient_io', true);
                } elseif (!hash_equals($identity->sha256, $verified->sha256) || $verified->size !== $identity->size) {
                    $fail->execute($attempt, 'content_identity_mismatch');

                    return;
                } else {
                    $result = $scanner->scan(new ScanInput($verified->stream, $attempt->revisionId, $attempt->blobId,
                        $attempt->driveId, $attempt->tenantId, $identity->scopeKey, $verified->sha256,
                        $verified->size, $revision->mime_type, $attempt->spec->scannerVersion,
                        $attempt->spec->policyVersion, $attempt->deadline));
                    if ($work->now() >= $attempt->deadline) {
                        $result = new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true);
                    }
                }
            }
        } catch (InvalidConfigurationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $result = new ScanResult(ScanVerdict::Failed, 'unknown');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $settle->execute($attempt, $result);
    }
}
