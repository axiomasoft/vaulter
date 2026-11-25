<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Models\Content\ScanBackfillRun;
use Vaulter\Drive\Models\Content\ScanPolicyState;
use Vaulter\Drive\Models\Drive;

/** @internal Persisted per-drive scan activation state and its backfill run; activation itself is ActivateScanPolicyAction. */
#[Internal]
final readonly class ScanPolicyStateRepository
{
    public function __construct(private DriveOptions $config, private ScanAuthorityRepository $authority) {}

    /**
     * The persisted states of several specs of one drive, keyed by predicate key; a spec without a row is absent.
     * The drive row is read once for its authoritative id and tenant, and the states in one query.
     *
     * @param  iterable<ScanPolicySpec>       $specs
     * @return array<string, ScanPolicyState>
     */
    public function statesFor(Drive $drive, iterable $specs): array
    {
        $keys = [];
        foreach ($specs as $spec) {
            $keys[] = $spec->predicateKey;
        }
        if ($keys === []) {
            return [];
        }
        $class = $this->config->model('scan_policy_state');
        $authoritative = $this->authority->persistedDriveOrFail($drive);
        $states = [];
        foreach ($class::query()->where('drive_id', $authoritative->id)->where('tenant_id', $authoritative->tenant_id)->whereIn('predicate_key', $keys)->get() as $state) {
            $states[$state->predicate_key] = $state;
        }

        return $states;
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
