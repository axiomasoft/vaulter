<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use Illuminate\Support\Str;
use LogicException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Creates scan work and its request ledger row; SQL owns generation fencing.
 *
 * @internal The caller's outer transaction holds the revision-family locks, the work row is taken last.
 */
final readonly class RequestScanWorkAction
{
    public function __construct(private ScanAuthorityRepository $authority, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence, private ReuseScanEvidenceAction $reuse, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function ensure(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context): ScanObligation
    {
        return $this->request($revision, $spec, $requestKey, $context, false);
    }

    public function requestRescan(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context): ScanObligation
    {
        return $this->request($revision, $spec, $requestKey, $context, true);
    }

    private function request(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context, bool $rescan): ScanObligation
    {
        if ($this->database->connection()->transactionLevel() === 0 || !Str::isUuid($requestKey)) {
            throw new LogicException('Scan requests require an owning transaction and UUID request key.');
        }
        if ($spec->scannerKey === 'none' || $spec->scannerVersion === 'none') {
            throw new LogicException('Scan work requires a configured scanner identity.');
        }
        $snapshot = $this->snapshots->load($revision, true);
        if ($this->tenancy->enabled()) {
            $this->boundary->assertDrive($snapshot['drive']);
        }
        if ((string) $snapshot['drive']->id !== (string) $context->driveId()) {
            throw new LogicException('Scan request scope differs.');
        }
        $this->transitions->currentSpec($snapshot['drive'], $spec->specKey());
        $work = $this->work->lockForRevisionSpec($snapshot['revision']->id, $spec->specKey());
        if ($work !== null && (!$rescan || $this->work->hasRequest($work, $requestKey))) {
            return $work;
        }
        $generation = $work === null ? 1 : $this->transitions->increment($work->generation);
        $identity = $snapshot['identity'];
        $r = $snapshot['revision'];
        $blob = $snapshot['blob'];
        if ($identity === null && $r->manifest_blob_id !== null) {
            $blob = $this->authority->blob($r->manifest_blob_id);
        }
        $unsupported = $identity === null;
        $identityHash = $identity?->fingerprint() ?? hash('sha256', json_encode(['manifest_blob_id' => $r->manifest_blob_id, 'payload_kind' => $r->manifest_blob_id !== null ? 'manifest' : 'missing', 'revision_id' => (string) $r->id, 'sha256' => $r->sha256, 'size' => $r->size_bytes], JSON_THROW_ON_ERROR));
        $now = $this->work->now();
        $work ??= $this->work->make();
        $createdAt = $work->getAttribute('created_at') ?? $now;
        $work->forceFill(['revision_id' => $r->id, 'drive_id' => $snapshot['drive']->id,
            'tenant_id' => $snapshot['drive']->tenant_id, 'blob_id' => $blob?->id, 'scope_key' => $blob === null ? 'unavailable' : $blob->scope_key,
            'content_identity' => $identityHash, 'sha256' => $r->sha256, 'size_bytes' => $r->size_bytes,
            'spec_key' => $spec->specKey(), 'scanner_key' => $spec->scannerKey, 'scanner_version' => $spec->scannerVersion,
            'policy_version' => $spec->policyVersion, 'generation' => $generation, 'attempt' => 0,
            'request_key' => $requestKey, 'state' => 'pending', 'outcome' => null, 'reason' => null, 'retryable' => false,
            'available_at' => $now, 'lease_token' => null, 'lease_expires_at' => null, 'last_published_at' => null,
            'created_at' => $createdAt, 'updated_at' => $now])->save();
        $this->work->recordRequest($work, $requestKey, $now, $rescan ? 'explicit_rescan' : 'admission', $context->correlationId);
        if ($unsupported) {
            $work->forceFill(['attempt' => 1])->save();
            $this->transitions->append($work, new ScanResult(ScanVerdict::Failed, $r->manifest_blob_id !== null || $blob?->chunked ? 'unsupported' : 'unknown'), $now);
            $work->forceFill(['state' => 'settled', 'outcome' => 'failed', 'reason' => $r->manifest_blob_id !== null || $blob?->chunked ? 'unsupported' : 'unknown'])->save();
        } elseif (!$rescan) {
            // A same-drive reupload may reuse existing exact-byte proof, but always
            // materializes its own request and evidence row before commit.
            $candidate = $this->evidence->cleanCandidate($work, $spec);
            if ($candidate !== null && !$this->evidence->infected($r)) {
                $this->reuse->execute($r, $candidate, $spec, $work);
                $work->refresh();
            }
        }

        return $work;
    }
}
