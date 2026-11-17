<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Carbon\CarbonImmutable;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;
use Vaulter\Drive\Configuration\DriveModelsOptions;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Support\Identity;

/**
 * A changed scan policy takes effect per drive only once it is activated and its backfill ran:
 * until then the drive still enforces the old policy while the config names the new one.
 */
#[Internal]
final readonly class ScanPolicyCheck implements DoctorCheck
{
    /** Hours a backfill may go without progress before it counts as stalled. */
    private const int STALLED_HOURS = 24;

    public function __construct(private DriveModelsOptions $models, private ScanPolicyResolver $policies) {}

    public function key(): string
    {
        return 'drive.scan.policy';
    }

    public function title(): string
    {
        return 'Scan policy activation';
    }

    public function run(DoctorContext $context): array
    {
        $drives = $this->models->drive::query()->orderBy('id')->limit($context->sampleLimit)->get();
        $states = [];
        if ($drives->isNotEmpty()) {
            foreach ($this->models->scanPolicyState::query()->whereIn('drive_id', $drives->modelKeys())->get() as $state) {
                $states[Identity::stringify($state->drive_id)][$state->predicate_key] = $state->config_fingerprint;
            }
        }

        $pending = 0;
        $unknownProfile = 0;
        foreach ($drives as $drive) {
            try {
                $specs = $this->policies->desired($drive);
            } catch (Throwable) {
                $unknownProfile++;

                continue;
            }
            foreach ($specs as $spec) {
                $fingerprint = $states[Identity::stringify($drive->getKey())][$spec->predicateKey] ?? null;
                if ($fingerprint === null ? $spec->mode !== ScanMode::Off : $fingerprint !== $spec->configFingerprint()) {
                    $pending++;
                    break;
                }
            }
        }

        $stalledBefore = CarbonImmutable::instance($context->now)->utc()->subHours(self::STALLED_HOURS);
        $stalled = $this->models->scanBackfillRun::query()->where('state', '!=', 'completed')->where('updated_at', '<', $stalledBefore)->count();

        $status = $context->strictStatus();
        $severity = $context->strictSeverity();
        $findings = [];
        if ($pending > 0) {
            $findings[] = DoctorFinding::at(
                status: $status,
                severity: $severity,
                code: 'drive.scan.policy_cutover_pending',
                message: $pending.' drive(s) have a scan policy that was changed in the config but not activated.',
                remediation: 'run vaulter:scan:rescan --activate-policy --drive=<id> --dry-run, then without --dry-run',
                evidence: ['drives' => $pending, 'sample' => $context->sampleLimit],
            );
        }
        if ($unknownProfile > 0) {
            $findings[] = DoctorFinding::at(
                status: $status,
                severity: $severity,
                code: 'drive.scan.policy_unknown_profile',
                message: $unknownProfile.' drive(s) belong to a profile that is not configured, so their scan policy cannot be resolved.',
                remediation: 'restore the profile or migrate the drives to a configured one',
                evidence: ['drives' => $unknownProfile],
            );
        }
        if ($stalled > 0) {
            $findings[] = DoctorFinding::at(
                status: $status,
                severity: $severity,
                code: 'drive.scan.backfill_stalled',
                message: $stalled.' scan backfill run(s) made no progress for '.self::STALLED_HOURS.' hours.',
                remediation: 'check that vaulter:scan:reconcile is scheduled and the scan queue has a worker',
                evidence: ['runs' => $stalled],
            );
        }

        return $findings === [] ? [DoctorFinding::ok(code: 'drive.scan.policy_current', message: 'Every sampled drive runs the configured scan policy and no backfill is stalled.', evidence: ['sample' => $context->sampleLimit])] : $findings;
    }
}
