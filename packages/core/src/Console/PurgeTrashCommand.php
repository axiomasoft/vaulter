<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vaulter\Actions\Drive\ForceDeleteNodeAction;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;

/**
 * Permanently purge trashed nodes past their retention window (D17 / §2.10). It
 * finds the trash ROOTS whose trashed_at is older than now() - retention_days and
 * force-deletes each subtree through {@see ForceDeleteNodeAction}, so the
 * version↔blob edges detach (the bytes become GC-eligible — the GC command reclaims
 * them later) and the quota is released. Schedulable.
 *
 * Only trash roots are processed: a trashed node whose parent is also trashed is
 * skipped, because purging the ancestor's subtree already covers it (no
 * double-purge). The retention can be overridden per-run with --days.
 */
final class PurgeTrashCommand extends Command
{
    protected $signature = 'vaulter:purge-trash
        {--days= : Override the retention window (config vaulter.trash.retention_days)}';

    protected $description = 'Permanently delete trashed nodes whose retention window has elapsed.';

    public function handle(NodeReadRepository $nodes, ForceDeleteNodeAction $forceDelete): int
    {
        $retentionDays = $this->retentionDays();
        $threshold = Carbon::now()->subDays($retentionDays);

        $this->info(sprintf(
            'Purging nodes trashed on or before %s (retention: %d day%s).',
            $threshold->toDateTimeString(),
            $retentionDays,
            $retentionDays === 1 ? '' : 's',
        ));

        $purgedRoots = 0;

        // Materialise the candidates first: ForceDeleteNodeAction soft-deletes
        // rows, which would shift a live cursor mid-iteration.
        $candidates = $nodes->trashedBefore($threshold)->get();

        foreach ($candidates as $node) {
            $forceDelete->execute(node: $node);
            $purgedRoots++;
        }

        $this->info(sprintf('Purged %d trashed subtree%s.', $purgedRoots, $purgedRoots === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * The retention window: the --days override when a non-negative integer, else
     * the configured default. Floored at 0 (purge as soon as trashed).
     */
    private function retentionDays(): int
    {
        $override = $this->option('days');

        if (is_string($override) && is_numeric($override)) {
            return max((int) $override, 0);
        }

        return Config::trashRetentionDays();
    }
}
