<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Illuminate\Support\Str;
use LogicException;
use Vaulter\Actions\Content\RequestScanWorkAction;
use Vaulter\Actions\Content\ReuseScanEvidenceAction;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Content\ScanEvidenceRepository;

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
            } elseif ($obligation->state === 'settled' && $obligation->outcome === 'failed') {
                $failed = true;
            } elseif ($obligation->state !== 'settled') {
                $pending = true;
                $this->publisher->afterCommit($obligation);
            }
        }
        $revision->forceFill(['scan_status' => $pending ? 'pending' : ($clean ? 'clean' : ($failed ? 'failed' : 'not_required'))])->save();
    }
}
