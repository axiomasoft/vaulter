<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Content;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Content\ScanEvidence;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Models\Content\ScanEvidenceRecord;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Content\ScanRequest;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Models\Cas\Blob;

/** @internal Immutable scan evidence persistence; availability decisions go through ContentAvailabilityGuard. */
#[Internal]
final readonly class ScanEvidenceRepository
{
    public function __construct(private DriveOptions $config, private ScanSnapshot $snapshots) {}

    public function identity(FileRevision $revision): ?ImmutableContentIdentity
    {
        return $this->snapshots->load($revision)['identity'];
    }

    public function infected(FileRevision $revision): bool
    {
        return $this->infectedIn($this->snapshots->load($revision));
    }

    /**
     * infected() over a snapshot the caller already loaded.
     *
     * @param array{revision:FileRevision,file:File,node:Node,drive:Drive,blob:?Blob,identity:?ImmutableContentIdentity} $snapshot
     */
    public function infectedIn(array $snapshot): bool
    {
        $r = $snapshot['revision'];
        $blob = $snapshot['blob'];
        if ($r->scan_status === 'infected' || $r->getAttribute('quarantined_at') !== null || $blob?->getAttribute('quarantined_at') !== null) {
            return true;
        }
        if ($blob === null) {
            return false;
        }
        $recordClass = $this->config->model('scan_evidence');
        if ($recordClass::query()->where('blob_id', $blob->id)->where('scope_key', $blob->scope_key)
            ->where('tenant_id', $snapshot['drive']->tenant_id)->where('outcome', 'infected')->whereNull('revoked_at')->exists()) {
            return true;
        }
        // Before versioned evidence exists, a legacy infected revision using this
        // exact physical blob is still authoritative. Digest lookup is never authority.
        $revisionClass = $this->config->model('file_revision');
        $edgeClass = $this->config->model('file_revision_blob');

        return $revisionClass::query()->where('tenant_id', $snapshot['drive']->tenant_id)->where('scan_status', 'infected')
            ->where(function (Builder $query) use ($blob, $edgeClass): void {
                $query->where('blob_id', $blob->id)->orWhereIn('id', $edgeClass::query()->select('file_revision_id')->where('blob_id', $blob->id));
            })->exists();
    }

    public function compatible(FileRevision $revision, ScanPolicySpec $spec): ?ScanEvidence
    {
        return $this->compatibleIn($this->snapshots->load($revision), $spec);
    }

    /**
     * compatible() over a snapshot the caller already loaded.
     *
     * @param array{revision:FileRevision,file:File,node:Node,drive:Drive,blob:?Blob,identity:?ImmutableContentIdentity} $snapshot
     */
    public function compatibleIn(array $snapshot, ScanPolicySpec $spec): ?ScanEvidence
    {
        $identity = $snapshot['identity'];
        if ($identity === null || $this->infectedIn($snapshot)) {
            return null;
        }
        $class = $this->config->model('scan_evidence');
        $record = $class::query()->where('revision_id', $snapshot['revision']->id)
            ->where('drive_id', $snapshot['drive']->id)->where('tenant_id', $snapshot['drive']->tenant_id)
            ->where('blob_id', $identity->blobId)->where('scope_key', $identity->scopeKey)
            ->where('content_identity', $identity->fingerprint())->where('sha256', $identity->sha256)
            ->where('size_bytes', $identity->size)->where('spec_key', $spec->specKey())
            ->where('scanner_key', $spec->scannerKey)->where('scanner_version', $spec->scannerVersion)
            ->where('policy_version', $spec->policyVersion)->where('outcome', 'clean')->whereNull('revoked_at')->orderByDesc('id')->first();

        return $record === null ? null : $this->value($record);
    }

    /** Exact-byte clean proof of this drive and tenant that a same-drive reupload may reuse. */
    public function cleanCandidate(ScanObligation $work, ScanPolicySpec $spec): ?ScanEvidence
    {
        $class = $this->config->model('scan_evidence');
        $record = $class::query()->where('drive_id', $work->drive_id)->where('tenant_id', $work->tenant_id)
            ->where('blob_id', $work->blob_id)->where('content_identity', $work->content_identity)
            ->where('spec_key', $spec->specKey())->where('outcome', 'clean')->whereNull('revoked_at')->orderByDesc('id')->first();

        return $record === null ? null : $this->value($record);
    }

    public function findOrFail(int|string $id): ScanEvidenceRecord
    {
        $class = $this->config->model('scan_evidence');

        return $class::query()->whereKey($id)->firstOrFail();
    }

    public function lockOrFail(int|string $id): ScanEvidenceRecord
    {
        $class = $this->config->model('scan_evidence');

        return $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function recordReuse(ScanObligation $work, ScanRequest $request, ScanEvidenceRecord $source, DateTimeImmutable $observedAt): ScanEvidenceRecord
    {
        $class = $this->config->model('scan_evidence');
        $row = new $class;
        $row->forceFill($work->only(['revision_id', 'drive_id', 'tenant_id', 'blob_id', 'scope_key', 'content_identity', 'sha256', 'size_bytes', 'spec_key', 'scanner_key', 'scanner_version', 'policy_version', 'generation']));
        $row->forceFill(['obligation_id' => $work->id, 'request_id' => $request->id, 'attempt' => 1,
            'outcome' => 'clean', 'reason' => null, 'retryable' => false, 'observed_at' => $observedAt, 'reused_from_id' => $source->id])->save();

        return $row;
    }

    public function hasAttempt(ScanRequest $request, int $attempt): bool
    {
        $class = $this->config->model('scan_evidence');

        return $class::query()->where('request_id', $request->id)->where('attempt', $attempt)->exists();
    }

    public function append(ScanObligation $work, ScanRequest $request, ScanResult $result, DateTimeImmutable $observedAt): void
    {
        $class = $this->config->model('scan_evidence');
        $record = new $class;
        $record->forceFill($work->only(['revision_id', 'drive_id', 'tenant_id', 'blob_id', 'scope_key', 'content_identity', 'sha256', 'size_bytes', 'spec_key', 'scanner_key', 'scanner_version', 'policy_version', 'generation', 'attempt']));
        $record->forceFill(['obligation_id' => $work->id, 'request_id' => $request->id, 'outcome' => $result->verdict->value,
            'reason' => $result->reason, 'retryable' => $result->retryable, 'observed_at' => $observedAt])->save();
    }

    public function value(ScanEvidenceRecord $record): ScanEvidence
    {
        return new ScanEvidence($record->id, $record->revision_id, $record->blob_id, $record->drive_id, $record->tenant_id,
            $record->scope_key, $record->content_identity, $record->spec_key, ScanVerdict::from($record->outcome),
            $record->revoked_at === null ? null : DateTimeImmutable::createFromInterface($record->revoked_at), DateTimeImmutable::createFromInterface($record->observed_at));
    }
}
