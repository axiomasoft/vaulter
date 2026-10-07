<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Repositories\Content\ScanObligationRepository;

/**
 * Settles work whose spec is no longer in the drive policy, so a stale worker can never record a result.
 *
 * @internal Locks only the work row.
 */
final readonly class SupersedeScanWorkAction
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanPolicyResolver $policies, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanObligation $obligation): bool
    {
        return $this->database->transaction(function () use ($obligation): bool {
            $work = $this->work->lockPersisted($obligation);
            if ($work === null || $work->generation !== $obligation->generation || $work->state === 'settled') {
                return false;
            }
            $driveClass = $this->config->model('drive');
            $drive = $driveClass::query()->whereKey($work->drive_id)->firstOrFail();
            $specs = $this->policies->forDrive($drive);
            if (array_any($specs, static fn (ScanPolicySpec $spec): bool => $spec->specKey() === $work->spec_key)) {
                return false;
            }
            $this->transitions->terminal($work, 'superseded_policy', $this->work->now());

            return true;
        });
    }
}
