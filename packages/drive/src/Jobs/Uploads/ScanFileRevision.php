<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs\Uploads;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Vaulter\Actions\Content\ClaimScanWorkAction;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Drive\Content\PrivateScanReader;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\Tenancy\TenancyBypass;

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

    public function handle(ScanObligationRepository $work, ClaimScanWorkAction $claim, PrivateScanReader $reader, ScanSnapshot $snapshots,
        ScanConfig $config): void
    {
        if (app(TenancyConfig::class)->enabled()) {
            app(TenancyBoundary::class)->assertJobDrive($this->driveId, $this->tenantId);
        }
        app(TenancyBypass::class)->suspend(fn () => $this->execute($work, $claim, $reader, $snapshots, $config));
    }

    private function execute(ScanObligationRepository $work, ClaimScanWorkAction $claim, PrivateScanReader $reader, ScanSnapshot $snapshots, ScanConfig $config): void
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
            $revision = $work->fencedAttempt($attempt);
            if ($revision === null) {
                $work->failAttempt($attempt, 'content_identity_mismatch');

                return;
            }
            $snapshot = $snapshots->load($revision);
            $identity = $snapshot['identity'];
            if ($identity === null || $identity->fingerprint() !== $attempt->contentIdentity) {
                $work->failAttempt($attempt, 'content_identity_mismatch');

                return;
            }
        } catch (\LogicException) {
            $work->failAttempt($attempt, 'content_identity_mismatch');

            return;
        }
        $stream = null;
        try {
            try {
                $stream = $reader->open($attempt);
            } catch (\LogicException) {
                $work->failAttempt($attempt, 'content_identity_mismatch');

                return;
            }
            if (!is_resource($stream)) {
                if ($work->fencedAttempt($attempt) === null) {
                    $work->failAttempt($attempt, 'content_identity_mismatch');

                    return;
                }
                $result = new ScanResult(ScanVerdict::Failed, 'transient_io', true);
            } else {
                if ($scanner->key() !== $attempt->spec->scannerKey || $scanner->version() !== $attempt->spec->scannerVersion) {
                    throw new InvalidConfigurationException('Scanner identity changed before byte IO.');
                }
                $result = $scanner->scan(new ScanInput($stream, $attempt->revisionId, $attempt->blobId,
                    $attempt->driveId, $attempt->tenantId, $identity->scopeKey, $identity->sha256,
                    $identity->size, $revision->mime_type, $attempt->spec->scannerVersion,
                    $attempt->spec->policyVersion, $attempt->deadline));
                if ($work->now() >= $attempt->deadline) {
                    $result = new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true);
                }
            }
        } catch (InvalidConfigurationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $result = new ScanResult(ScanVerdict::Failed, 'unknown');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $work->settle($attempt, $result);
    }
}
