<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Content;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanPolicySpec;
use Vaulter\Drive\Content\ScanWorkTransitions;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;

/**
 * Settles work whose spec is no longer in the drive policy, so a stale worker can never record a result.
 *
 * Locks only the work row.
 */
#[Internal]
final readonly class SupersedeScanWorkAction
{
    public function __construct(private ScanAuthorityRepository $authority, private VaulterDatabase $database, private ScanPolicyResolver $policies, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanObligation $obligation): bool
    {
        return $this->database->transaction(function () use ($obligation): bool {
            $work = $this->work->lockPersisted($obligation);
            if ($work === null || $work->generation !== $obligation->generation || $work->state === 'settled') {
                return false;
            }
            $drive = $this->authority->driveOrFail($work->drive_id);
            $specs = $this->policies->forDrive($drive);
            if (array_any($specs, static fn (ScanPolicySpec $spec): bool => $spec->specKey() === $work->spec_key)) {
                return false;
            }
            $this->transitions->terminal($work, 'superseded_policy', $this->work->now());

            return true;
        });
    }
}
