<?php

declare(strict_types=1);

namespace Vaulter\Media\Console;

use Illuminate\Console\Command;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\VaulterManager;

/**
 * Two-phase, race-safe blob garbage collection (H.3). ACL-blind, system context:
 * mark unreferenced committed blobs as tombstoned (with a grace deadline), then
 * sweep tombstoned blobs past grace to a physical delete. --pending also clears
 * crashed-staging PENDING rows. The grace window comes from the configuration key ('vaulter.gc.
 * grace_hours') (floored at 1h) unless --grace overrides it.
 */
final class GcBlobsCommand extends Command
{
    public function __construct(
        private readonly MediaConfig $mediaConfig,
    ) {
        parent::__construct();
    }

    protected $signature = 'vaulter:blobs:gc
        {--grace= : Override the GC grace window in hours (floored at 1h)}
        {--pending : Also sweep crashed-staging PENDING blobs}';

    protected $description = 'Garbage-collect unreferenced Vaulter blobs (two-phase, race-safe — H.3)';

    public function handle(BlobGarbageCollector $collector): int
    {
        return app(VaulterManager::class)->withoutTenancy(fn (): int => $this->runMaintenance($collector));
    }

    private function runMaintenance(BlobGarbageCollector $collector): int
    {
        $grace = $this->graceOption();

        $result = $collector->collect(graceHours: $grace);

        $this->components->info(
            "Tombstoned {$result['tombstoned']}, swept {$result['swept']}, skipped {$result['skipped']} ".
            '(grace '.$this->mediaConfig->gcGraceHours($grace).'h).',
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
