<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Drive\Drive;
use Vaulter\Quota\QuotaCalculator;
use Vaulter\Repositories\Drive\DriveStoreRepository;

/**
 * Recount one drive's eager used_bytes from its retained revisions. The
 * count is read under the exclusive tree lock, where no upload, purge or revision
 * delete of the drive can commit in between, so the stored value is replaced by what
 * the revisions sum to at that moment, never by a number read before a concurrent
 * change. Running it again changes nothing.
 */
#[Internal]
final readonly class RecountDriveQuotaAction
{
    public function __construct(private VaulterDatabase $database, private QuotaCalculator $quota, private DriveStoreRepository $drives) {}

    /** @return array{before: int, after: int, drift: int, changed: bool} */
    public function execute(int|string $driveId, bool $dryRun = false): array
    {
        return $this->database->transaction(function () use ($driveId, $dryRun): array {
            $this->database->lockTree($driveId);
            $drive = $this->drives->lock($driveId);
            $before = $drive->used_bytes;
            $after = $this->quota->usedBytes($drive->id);
            $changed = $before !== $after;
            if ($changed && !$dryRun) {
                $this->set($drive, $after);
            }

            return ['before' => $before, 'after' => $after, 'drift' => $after - $before, 'changed' => $changed];
        });
    }

    private function set(Drive $drive, int $bytes): void
    {
        $drive->forceFill(['used_bytes' => $bytes])->save();
    }
}
