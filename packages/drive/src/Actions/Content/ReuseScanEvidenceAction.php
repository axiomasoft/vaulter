<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Content;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanEvidence;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Enums\ScanWorkState;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;

/**
 * Settles a fresh request clean from exact-byte proof of the same scope, with its own evidence row.
 *
 * Runs inside the request transaction, which already holds the revision-family locks; the work row is locked last.
 */
#[Internal]
final readonly class ReuseScanEvidenceAction
{
    public function __construct(private ScanAuthorityRepository $authority, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence, private ScanObligationRepository $work) {}

    public function execute(FileRevision $target, ScanEvidence $source, ScanPolicySpec $spec, ScanObligation $obligation): ScanEvidence
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Evidence reuse requires the owning transaction.');
        }
        $snapshot = $this->snapshots->load($target);
        $record = $this->evidence->findOrFail($source->id);
        $sourceRevision = $this->authority->revisionOrFail($record->revision_id);
        $validated = $this->evidence->compatible($sourceRevision, $spec);
        $identity = $snapshot['identity'];
        if ($validated === null || (string) $validated->id !== (string) $record->id || $identity === null
            || $validated->contentIdentity !== $identity->fingerprint() || (string) $validated->driveId !== (string) $snapshot['drive']->id
            || (string) $validated->tenantId !== (string) $snapshot['drive']->tenant_id || $this->evidence->infected($target)) {
            throw new LogicException('Evidence cannot be reused for this exact scoped content.');
        }
        $work = $this->work->lockPersistedOrFail($obligation);
        if ((string) $work->drive_id !== (string) $snapshot['drive']->id || (string) $work->tenant_id !== (string) $snapshot['drive']->tenant_id || (string) $work->revision_id !== (string) $snapshot['revision']->id || $work->spec_key !== $spec->specKey() || $work->content_identity !== $identity->fingerprint()) {
            throw new LogicException('Evidence reuse obligation differs.');
        }
        if ($work->state === ScanWorkState::Settled && $work->outcome === 'clean') {
            $existing = $this->evidence->compatible($target, $spec);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($work->attempt !== 0 || $work->state !== ScanWorkState::Pending) {
            throw new LogicException('Evidence reuse requires a fresh request.');
        }
        $now = $this->work->now();
        $row = $this->evidence->recordReuse($work, $this->work->requestFor($work), $record, $now);
        $work->forceFill(['state' => ScanWorkState::Settled, 'outcome' => 'clean', 'reason' => null, 'retryable' => false,
            'attempt' => 1, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();
        $target->forceFill(['scan_status' => ScanStatus::Clean->value])->save();

        return $this->evidence->value($row);
    }
}
