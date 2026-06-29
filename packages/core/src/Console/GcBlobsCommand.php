<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Cas\BlobGarbageCollector;
use Vaulter\Support\Config;

/**
 * Two-phase, race-safe blob garbage collection (H.3). ACL-blind, system context:
 * mark unreferenced committed blobs as tombstoned (with a grace deadline), then
 * sweep tombstoned blobs past grace to a physical delete. --pending also clears
 * crashed-staging PENDING rows. The grace window comes from config('vaulter.gc.
 * grace_hours') (floored at 1h) unless --grace overrides it.
 */
final class GcBlobsCommand extends Command
{
    protected $signature = 'vaulter:gc-blobs
        {--grace= : Override the GC grace window in hours (floored at 1h)}
        {--pending : Also sweep crashed-staging PENDING blobs}';

    protected $description = 'Garbage-collect unreferenced Vaulter blobs (two-phase, race-safe — H.3)';

    public function handle(BlobGarbageCollector $collector): int
    {
        $grace = $this->graceOption();

        $result = $collector->collect(graceHours: $grace);

        $this->components->info(
            "Tombstoned {$result['tombstoned']}, swept {$result['swept']}, skipped {$result['skipped']} ".
            '(grace '.Config::gcGraceHours($grace).'h).',
        );

        if ($this->option('pending')) {
            $pending = $collector->sweepPending(graceHours: $grace);

            $this->components->info("Pending sweep: swept {$pending['swept']}, skipped {$pending['skipped']}.");
        }

        return self::SUCCESS;
    }

    private function graceOption(): int
    {
        $value = $this->option('grace');

        return is_numeric($value) ? (int) $value : 0;
    }
}
