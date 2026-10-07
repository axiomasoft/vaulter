<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Models\Content\ScanBackfillRun;
use Vaulter\Models\Content\ScanPolicyState;
use Vaulter\Models\Drive\Drive;

/** @internal Persisted per-drive scan activation state and its backfill run; activation itself is ActivateScanPolicyAction. */
final readonly class ScanPolicyStateRepository
{
    public function __construct(private VaulterConfig $config, private ScanAuthorityRepository $authority) {}

    public function state(Drive $drive, ScanPolicySpec $spec): ?ScanPolicyState
    {
        $class = $this->config->model('scan_policy_state');
        $authoritative = $this->authority->persistedDriveOrFail($drive);

        return $class::query()->where('drive_id', $authoritative->id)->where('tenant_id', $authoritative->tenant_id)->where('predicate_key', $spec->predicateKey)->first();
    }

    public function lockState(int|string $driveId, string $predicateKey): ?ScanPolicyState
    {
        $class = $this->config->model('scan_policy_state');

        return $class::query()->where('drive_id', $driveId)->where('predicate_key', $predicateKey)->lockForUpdate()->first();
    }

    public function makeState(): ScanPolicyState
    {
        $class = $this->config->model('scan_policy_state');

        return new $class;
    }

    public function lockBackfillRun(int|string $driveId, string $specKey): ?ScanBackfillRun
    {
        $class = $this->config->model('scan_backfill_run');

        return $class::query()->where('drive_id', $driveId)->where('spec_key', $specKey)->lockForUpdate()->first();
    }

    public function makeBackfillRun(): ScanBackfillRun
    {
        $class = $this->config->model('scan_backfill_run');

        return new $class;
    }
}
