<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\RequestScanWorkAction;
use Vaulter\Drive\Actions\Content\ReuseScanEvidenceAction;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Enums\ScanWorkState;
use Vaulter\Drive\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;

#[Internal]
final readonly class ScanAdmission
{
    public function __construct(private VaulterDatabase $database, private ScanPolicyResolver $policies,
        private ScanEvidenceRepository $evidence, private RequestScanWorkAction $request, private ReuseScanEvidenceAction $reuse,
        private ScanWorkReconciler $publisher) {}

    public function next(DriveContext $context): int
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Scan admission requires the creation transaction.');
        }
        $drive = $context->drive->newQuery()->whereKey($context->driveId())->lockForUpdate()->firstOrFail();
        $this->policies->forDrive($drive);
        if ($drive->scan_admission_sequence >= PHP_INT_MAX) {
            throw new LogicException('Scan admission sequence is exhausted.');
        }
        $sequence = $drive->scan_admission_sequence + 1;
        $drive->forceFill(['scan_admission_sequence' => $sequence])->save();

        return $sequence;
    }

    public function admit(DriveContext $context, FileRevision $revision, ?FileRevision $source = null): void
    {
        if ($this->evidence->infected($revision) || ($source !== null && $this->evidence->infected($source))) {
            throw new ContentQuarantinedException;
        }
        $pending = false;
        $clean = false;
        $failed = false;
        foreach ($this->policies->forDrive($context->drive) as $spec) {
            if (!$this->policies->required($revision, $spec)) {
                continue;
            }
            $obligation = $this->request->ensure($revision, $spec, (string) Str::uuid(), $context);
            $candidate = $source === null ? $this->evidence->compatible($revision, $spec)
                : $this->evidence->compatible($source, $spec);
            if ($candidate !== null && (string) $candidate->driveId === (string) $context->driveId()) {
                $this->reuse->execute($revision, $candidate, $spec, $obligation);
                $clean = true;
            } elseif ($obligation->state === ScanWorkState::Settled && $obligation->outcome === 'failed') {
                $failed = true;
            } elseif ($obligation->state !== ScanWorkState::Settled) {
                $pending = true;
                $this->publisher->afterCommit($obligation);
            }
        }
        $revision->forceFill(['scan_status' => ($pending ? ScanStatus::Pending : ($clean ? ScanStatus::Clean : ($failed ? ScanStatus::Failed : ScanStatus::NotRequired)))->value])->save();
    }
}
