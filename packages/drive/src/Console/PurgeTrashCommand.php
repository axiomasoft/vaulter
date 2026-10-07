<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vaulter\VaulterManager;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;

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
 *
 * TOCTOU guard (P2.2 review fix): candidates are materialised up front (see
 * below), so a per-node transaction can run well after the node was read — a
 * concurrent restore in that window must not be steamrolled by an irreversible
 * physical delete. Each transaction re-verifies + row-locks the root via
 * {@see NodeStoreRepository::lockTrashedRoot()} before touching anything, and
 * resolves the subtree fresh from that locked state.
 */
final class PurgeTrashCommand extends Command
{
    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DriveConfig $driveConfig,
        private readonly ProfileRegistry $profiles,
    ) {
        parent::__construct();
    }

    protected $signature = 'vaulter:purge-trash
        {--days= : Override the retention window (config vaulter.trash.retention_days)}';

    protected $description = 'Permanently delete trashed nodes whose retention window has elapsed.';

    public function handle(
        NodeReadRepository $nodes,
        NodeStoreRepository $store,
        PurgeNodeAction $forceDelete,
        MetricsRecorder $metrics,
    ): int {
        return app(VaulterManager::class)->withoutTenancy(fn (): int => $this->runMaintenance($nodes, $store, $forceDelete, $metrics));
    }

    private function runMaintenance(NodeReadRepository $nodes, NodeStoreRepository $store, PurgeNodeAction $forceDelete, MetricsRecorder $metrics): int
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
        $purgedNodeCount = 0;

        $metrics->timing(
            name: 'vaulter_purge_duration_seconds',
            operation: function () use ($nodes, $store, $forceDelete, $metrics, $threshold, &$purgedRoots, &$purgedNodeCount): void {
                // Materialise the candidates first: the transaction below mutates rows,
                // which would shift a live cursor mid-iteration.
                $candidates = $nodes->trashedBefore(Carbon::now())->get();

                foreach ($candidates as $node) {
                    $drive = $node->storage()->first();
                    if ($drive === null) {
                        continue;
                    }
                    $override = $this->option('days');
                    $days = (is_string($override) || is_int($override)) && is_numeric($override)
                        ? max((int) $override, 0)
                        : ($this->profiles->get($drive->profile)->getTrashRetentionDays() ?? $this->driveConfig->trashRetentionDays());
                    $threshold = Carbon::now()->subDays($days);
                    $purged = $this->database->transaction(function () use ($node, $threshold, $nodes, $forceDelete, $store, $metrics): int {
                        // Re-verify + row-lock under the SAME transaction that performs the
                        // physical delete (TOCTOU guard, P2.2 review fix): the candidate
                        // above may be stale by the time this transaction actually runs. A
                        // concurrent restore clears trashed_at, so the recheck returns null
                        // and this subtree is skipped instead of being purged out from
                        // under a user who just restored it.
                        // Tree lock first, as every tree write takes it before node rows.
                        $this->database->lockTree($node->drive_id);
                        $locked = $store->lockTrashedRoot(Identity::key($node->getKey()), $threshold);

                        if ($locked === null) {
                            return 0;
                        }

                        // The subtree ids MUST be resolved from the freshly-locked node
                        // (not the pre-transaction one) and BEFORE ForceDeleteNodeAction
                        // runs — it soft-deletes the rows, and
                        // NodeReadRepository::subtree() applies the SoftDeletes scope, so a
                        // post-force-delete read would see nothing.
                        $subtreeIds = $nodes->subtree($locked)->modelKeys();

                        $metrics->histogram(
                            name: 'vaulter_purge_batch_nodes',
                            value: count($subtreeIds),
                        );

                        $forceDelete->execute(node: $locked);
                        $store->purgeSubtree(collect($subtreeIds));

                        return count($subtreeIds);
                    });

                    if ($purged > 0) {
                        $purgedRoots++;
                        $purgedNodeCount += $purged;
                    }
                }
            },
        );

        if ($purgedNodeCount > 0) {
            $metrics->counter(name: 'vaulter_purge_nodes_total', amount: $purgedNodeCount);
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

        if ((is_string($override) || is_int($override)) && is_numeric($override)) {
            return max((int) $override, 0);
        }

        return $this->driveConfig->trashRetentionDays();
    }
}
