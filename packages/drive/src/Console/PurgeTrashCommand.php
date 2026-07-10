<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Actions\ForceDeleteNodeAction;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

/**
 * Permanently purge trashed nodes past their retention window (D17 / §2.10 /
 * F-P1.12-02). It finds the trash ROOTS whose trashed_at is older than
 * now() - retention_days and, per subtree, (1) runs
 * {@see ForceDeleteNodeAction} — the stage-2 soft-delete that detaches every
 * version↔blob edge (the bytes become GC-eligible — the GC command reclaims them
 * later) and releases the quota, then (2) PHYSICALLY purges the same subtree's
 * rows ({@see NodeStoreRepository::purgeSubtree()}) so FK cascadeOnDelete removes
 * the dependent version rows too — "purge" now actually purges, instead of
 * leaving inline document content live in the database forever. Both steps run
 * in one transaction per subtree (the purge is irreversible). Schedulable.
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

    public function handle(
        NodeReadRepository $nodes,
        NodeStoreRepository $store,
        ForceDeleteNodeAction $forceDelete,
    ): int {
        $retentionDays = $this->retentionDays();
        $threshold = Carbon::now()->subDays($retentionDays);

        $this->info(sprintf(
            'Purging nodes trashed on or before %s (retention: %d day%s).',
            $threshold->toDateTimeString(),
            $retentionDays,
            $retentionDays === 1 ? '' : 's',
        ));

        $purgedRoots = 0;

        // Materialise the candidates first: the transaction below mutates rows,
        // which would shift a live cursor mid-iteration.
        $candidates = $nodes->trashedBefore($threshold)->get();

        foreach ($candidates as $node) {
            // The subtree ids MUST be resolved before ForceDeleteNodeAction runs —
            // it soft-deletes the rows, and NodeReadRepository::subtree() applies
            // the SoftDeletes scope, so a post-force-delete read would see nothing.
            $subtreeIds = $nodes->subtree($node)->modelKeys();

            DB::transaction(function () use ($node, $subtreeIds, $forceDelete, $store): void {
                $forceDelete->execute(node: $node);
                $store->purgeSubtree(collect($subtreeIds));
            });

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
