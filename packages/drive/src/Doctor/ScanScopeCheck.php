<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;
use Vaulter\Drive\Configuration\DriveModelsOptions;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Support\Identity;

/** Scan work is scoped by drive and tenant; work outside its drive's scope is never completed correctly. */
#[Internal]
final readonly class ScanScopeCheck implements DoctorCheck
{
    public function __construct(private DriveModelsOptions $models, private ScanPolicyResolver $policies) {}

    public function key(): string
    {
        return 'drive.scan.scope';
    }

    public function title(): string
    {
        return 'Scan work scope';
    }

    public function run(DoctorContext $context): array
    {
        $limit = $context->sampleLimit;
        $strayed = ['obligations_without_drive_scope' => $this->strayed('scan_obligation', $limit), 'backfill_runs_without_drive_scope' => $this->strayed('scan_backfill_run', $limit), 'unavailable_scope_under_required_policy' => $this->unavailableUnderRequiredPolicy($limit)];
        $strayed = array_filter($strayed, static fn (int $count): bool => $count > 0);

        if ($strayed === []) {
            return [DoctorFinding::ok(code: 'drive.scan.scope_ok', message: 'Every sampled scan obligation and backfill run belongs to an existing drive of its tenant.', evidence: ['sample' => $limit])];
        }

        return [DoctorFinding::error(
            code: 'drive.scan.scope_invalid',
            message: array_sum($strayed).' scan row(s) point at a missing drive, another tenant, or an unavailable content scope.',
            remediation: 'inspect the rows before vaulter:scan:reconcile; do not delete them by hand',
            evidence: ['sample' => $limit, ...$strayed],
        )];
    }

    /** Rows whose drive is gone, or belongs to another tenant than the row. */
    private function strayed(string $modelKey, int $limit): int
    {
        $model = new ($this->models->classFor($modelKey));
        $drives = (new ($this->models->drive))->getTable();

        return count($model->newQuery()->toBase()->from($model->getTable().' as w')
            ->whereNotExists(static fn (Builder $query): Builder => $query->selectRaw('1')->from($drives.' as d')->whereColumn('d.id', 'w.drive_id')
                ->where(static fn (Builder $tenant): Builder => $tenant->whereColumn('d.tenant_id', 'w.tenant_id')
                    ->orWhere(static fn (Builder $both): Builder => $both->whereNull('d.tenant_id')->whereNull('w.tenant_id'))))
            ->orderBy('w.id')->limit($limit)->pluck('w.id')->all());
    }

    /** Content whose scope could not be established, on a drive whose policy scans everything. */
    private function unavailableUnderRequiredPolicy(int $limit): int
    {
        $obligation = $this->models->scanObligation;
        $driveIds = array_map(Identity::stringify(...), $obligation::query()->where('scope_key', 'unavailable')->orderBy('id')->limit($limit)->pluck('drive_id')->all());
        if ($driveIds === []) {
            return 0;
        }

        $required = [];
        foreach ($this->models->drive::query()->whereIn('id', array_unique($driveIds))->get() as $drive) {
            try {
                $required[Identity::stringify($drive->getKey())] = array_any($this->policies->desired($drive), static fn (ScanPolicySpec $spec): bool => $spec->mode === ScanMode::AllContent);
            } catch (Throwable) {
                $required[Identity::stringify($drive->getKey())] = false;
            }
        }

        return count(array_filter($driveIds, static fn (string $id): bool => $required[$id] ?? false));
    }
}
