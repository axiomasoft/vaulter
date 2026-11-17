<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Drive\Configuration\DriveModelsOptions;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Quota\QuotaCalculator;
use Vaulter\Support\Identity;

/**
 * Compares the stored used_bytes with the quota formula. It reads through the same calculator
 * as the recount, without the tree lock: a drive written to meanwhile can show a drift that the
 * next run does not repeat.
 */
#[Internal]
final readonly class DrivesUsageCheck implements DoctorCheck
{
    public function __construct(private DriveModelsOptions $models, private QuotaCalculator $quota) {}

    public function key(): string
    {
        return 'drive.drives.usage';
    }

    public function title(): string
    {
        return 'Drive used bytes (sampled)';
    }

    public function run(DoctorContext $context): array
    {
        $drives = $this->models->drive::query()->where('status', '!=', DriveStatus::Deleting->value)
            ->orderBy('id')->limit($context->sampleLimit)->get(['id', 'used_bytes']);
        $ids = [];
        foreach ($drives as $drive) {
            $key = Identity::key($drive->getKey());
            if ($key !== null) {
                $ids[] = $key;
            }
        }
        $calculated = $this->quota->usedBytesByDrive($ids);

        $drifted = 0;
        $largest = 0;
        foreach ($drives as $drive) {
            $drift = abs(($calculated[Identity::stringify($drive->getKey())] ?? 0) - (is_numeric($used = $drive->getAttribute('used_bytes')) ? (int) $used : 0));
            if ($drift > 0) {
                $drifted++;
                $largest = max($largest, $drift);
            }
        }

        if ($drifted === 0) {
            return [DoctorFinding::ok(code: 'drive.drives.usage_ok', message: 'The used bytes of the sampled drives match their revisions.', evidence: ['checked' => $drives->count()])];
        }

        return [DoctorFinding::warning(
            code: 'drive.drives.usage_drift',
            message: $drifted.' of '.$drives->count().' sampled drive(s) store a used_bytes that differs from their retained revisions.',
            remediation: 'run vaulter:drives:recount --dry-run, then without it',
            evidence: ['drifted' => $drifted, 'checked' => $drives->count(), 'largest_drift_bytes' => $largest],
        )];
    }
}
