<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Drive\Configuration\DriveModelsOptions;

/**
 * The durable scan obligations of uploaded content. A lease nobody renews, work that never became due
 * and work that settled as failed all leave a revision unscanned, whether the webhooks module is active or not.
 */
#[Internal]
final readonly class ScanObligationsCheck implements DoctorCheck
{
    /** Minutes pending or retry-waiting work may be overdue before it is reported. */
    private const int OVERDUE_MINUTES = 30;

    /** Outcomes and reasons of work that settled without a verdict. */
    private const array UNSCANNED = ['failed', 'unsupported', 'too_large', 'password_protected', 'access_denied', 'unknown'];

    public function __construct(private DriveModelsOptions $models) {}

    public function key(): string
    {
        return 'drive.scan.obligations';
    }

    public function title(): string
    {
        return 'Scan obligations and leases';
    }

    public function run(DoctorContext $context): array
    {
        $now = CarbonImmutable::instance($context->now)->utc();
        $obligation = $this->models->scanObligation;

        $expiredLeases = $obligation::query()->where('state', 'leased')->where('lease_expires_at', '<', $now)->count();
        $overdue = $obligation::query()->whereIn('state', ['pending', 'retry_wait'])->where('available_at', '<', $now->subMinutes(self::OVERDUE_MINUTES))->count();
        $unscanned = [];
        foreach ($obligation::query()->toBase()
            ->where('state', 'settled')
            ->where(static fn (Builder $query): Builder => $query->whereIn('outcome', self::UNSCANNED)->orWhereIn('reason', self::UNSCANNED))
            ->selectRaw('coalesce(reason, outcome) as label, count(*) as total')->groupByRaw('coalesce(reason, outcome)')
            ->get() as $row) {
            $unscanned[is_string($row->label) ? $row->label : 'unknown'] = is_numeric($row->total) ? (int) $row->total : 0;
        }

        $findings = [];
        if ($expiredLeases > 0 || $overdue > 0) {
            $findings[] = DoctorFinding::at(
                status: $context->strictStatus(),
                severity: $context->strictSeverity(),
                code: 'drive.scan.obligations_stuck',
                message: $expiredLeases.' scan lease(s) expired and '.$overdue.' obligation(s) are overdue by more than '.self::OVERDUE_MINUTES.' minutes.',
                remediation: 'run vaulter:scan:reconcile --all-tenants --dry-run; check the scan queue worker',
                evidence: ['expired_leases' => $expiredLeases, 'overdue' => $overdue],
            );
        }
        if ($unscanned !== []) {
            $findings[] = DoctorFinding::warning(
                code: 'drive.scan.obligations_unscanned',
                message: array_sum($unscanned).' revision(s) settled without a scan verdict.',
                remediation: 'run vaulter:scan:reconcile --all-tenants --dry-run; retry with vaulter:scan:rescan --retry-failed',
                evidence: array_slice($unscanned, 0, 10, true),
            );
        }

        return $findings === [] ? [DoctorFinding::ok(code: 'drive.scan.obligations_ok', message: 'No stuck, overdue or unscanned scan obligation.')] : $findings;
    }
}
