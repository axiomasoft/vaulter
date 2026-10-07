<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\ScanEvidence;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Models\Content\ScanEvidenceRecord;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\OperationContext;

final readonly class ScanEvidenceRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanSnapshot $snapshots) {}

    public function identity(FileRevision $revision): ?ImmutableContentIdentity
    {
        return $this->snapshots->load($revision)['identity'];
    }

    public function infected(FileRevision $revision): bool
    {
        $snapshot = $this->snapshots->load($revision);
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
            ->where(function ($query) use ($blob, $edgeClass): void {
                $query->where('blob_id', $blob->id)->orWhereIn('id', $edgeClass::query()->select('file_revision_id')->where('blob_id', $blob->id));
            })->exists();
    }

    public function compatible(FileRevision $revision, ScanPolicySpec $spec): ?ScanEvidence
    {
        $snapshot = $this->snapshots->load($revision);
        $identity = $snapshot['identity'];
        if ($identity === null || $this->infected($snapshot['revision'])) {
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

    public function reuse(FileRevision $target, ScanEvidence $source, ScanPolicySpec $spec, ScanObligation $obligation): ScanEvidence
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Evidence reuse requires the owning transaction.');
        }
        $snapshot = $this->snapshots->load($target);
        $class = $this->config->model('scan_evidence');
        $record = $class::query()->whereKey($source->id)->firstOrFail();
        $revisionClass = $this->config->model('file_revision');
        $sourceRevision = $revisionClass::query()->whereKey($record->revision_id)->firstOrFail();
        $validated = $this->compatible($sourceRevision, $spec);
        $identity = $snapshot['identity'];
        if ($validated === null || (string) $validated->id !== (string) $record->id || $identity === null
            || $validated->contentIdentity !== $identity->fingerprint() || (string) $validated->driveId !== (string) $snapshot['drive']->id
            || (string) $validated->tenantId !== (string) $snapshot['drive']->tenant_id || $this->infected($target)) {
            throw new LogicException('Evidence cannot be reused for this exact scoped content.');
        }
        $workClass = $this->config->model('scan_obligation');
        $work = $workClass::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->firstOrFail();
        if ((string) $work->drive_id !== (string) $snapshot['drive']->id || (string) $work->tenant_id !== (string) $snapshot['drive']->tenant_id || (string) $work->revision_id !== (string) $snapshot['revision']->id || $work->spec_key !== $spec->specKey() || $work->content_identity !== $identity->fingerprint()) {
            throw new LogicException('Evidence reuse obligation differs.');
        }
        if ($work->state === 'settled' && $work->outcome === 'clean') {
            $existing = $this->compatible($target, $spec);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($work->attempt !== 0 || $work->state !== 'pending') {
            throw new LogicException('Evidence reuse requires a fresh request.');
        }
        $requestClass = $this->config->model('scan_request');
        $request = $requestClass::query()->where('obligation_id', $work->id)->where('generation', $work->generation)->firstOrFail();
        $row = new $class;
        $row->forceFill($work->only(['revision_id', 'drive_id', 'tenant_id', 'blob_id', 'scope_key', 'content_identity', 'sha256', 'size_bytes', 'spec_key', 'scanner_key', 'scanner_version', 'policy_version', 'generation']));
        $row->forceFill(['obligation_id' => $work->id, 'request_id' => $request->id, 'attempt' => 1,
            'outcome' => 'clean', 'reason' => null, 'retryable' => false, 'observed_at' => now('UTC'), 'reused_from_id' => $record->id])->save();
        $work->forceFill(['state' => 'settled', 'outcome' => 'clean', 'reason' => null, 'retryable' => false,
            'attempt' => 1, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now('UTC')])->save();
        $target->forceFill(['scan_status' => 'clean'])->save();

        return $this->value($row);
    }

    public function revoke(int|string $evidenceId, string $reason, OperationContext $context): void
    {
        if (!$context->actor->isSystem() || trim($reason) === '' || strlen($reason) > 191) {
            throw new LogicException('Evidence revocation requires trusted scoped maintenance and an audit reason.');
        }
        $this->database->transaction(function () use ($evidenceId, $reason, $context): void {
            $class = $this->config->model('scan_evidence');
            $record = $class::query()->whereKey($evidenceId)->firstOrFail();
            $revisionClass = $this->config->model('file_revision');
            $snapshot = $this->snapshots->load($revisionClass::query()->whereKey($record->revision_id)->firstOrFail(), true);
            if ((string) $snapshot['drive']->id !== (string) $context->driveId()) {
                throw new LogicException('Evidence revocation scope differs.');
            }
            $record = $class::query()->whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $record->forceFill(['revoked_at' => now('UTC'), 'revocation_reason' => $reason])->save();
        });
    }

    public function value(ScanEvidenceRecord $record): ScanEvidence
    {
        return new ScanEvidence($record->id, $record->revision_id, $record->blob_id, $record->drive_id, $record->tenant_id,
            $record->scope_key, $record->content_identity, $record->spec_key, ScanVerdict::from($record->outcome),
            $record->revoked_at?->toDateTimeImmutable(), $record->observed_at->toDateTimeImmutable());
    }
}
