<?php

declare(strict_types=1);

namespace Vaulter\Drive\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Console\Concerns\OpensWriteScope;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

#[Internal]
final class PurgeTrashCommand extends Command
{
    use OpensWriteScope;

    protected $signature = 'vaulter:trash:purge {--days= : Override the retention window}';

    protected $description = 'Permanently delete trashed nodes whose retention window has elapsed.';

    public function __construct(
        private readonly VaulterDatabase $database,
        private readonly DriveConfig $driveConfig,
        private readonly ProfileRegistry $profiles,
    ) {
        parent::__construct();
    }

    public function handle(NodeReadRepository $nodes, NodeStoreRepository $store, PurgeNodeAction $purge, MetricsRecorder $metrics): int
    {
        return Tenancy::without(fn (): int => $this->runMaintenance($nodes, $store, $purge, $metrics));
    }

    private function runMaintenance(NodeReadRepository $nodes, NodeStoreRepository $store, PurgeNodeAction $purge, MetricsRecorder $metrics): int
    {
        $purgedRoots = 0;
        $purgedNodeCount = 0;
        $failed = false;
        $metrics->timing(name: 'vaulter_purge_duration_seconds', operation: function () use (
            $nodes, $store, $purge, $metrics, &$purgedRoots, &$purgedNodeCount, &$failed,
        ): void {
            $candidates = $nodes->trashedBefore(Carbon::now())->get();
            foreach ($candidates as $node) {
                $context = null;
                try {
                    $drive = $node->storage()->first();
                    if ($drive === null) {
                        continue;
                    }
                    $context = DriveContext::system(drive: $drive, reason: 'purge-trash');
                    $override = $this->option('days');
                    $days = (is_string($override) || is_int($override)) && is_numeric($override)
                        ? max((int) $override, 0)
                        : ($this->profiles->get($drive->profile)->getTrashRetentionDays() ?? $this->driveConfig->trashRetentionDays());
                    $threshold = Carbon::now()->subDays($days);
                    $purged = $this->database->transaction(function () use ($node, $context, $threshold, $nodes, $store, $purge): int {
                        $this->database->lockAggregate('drives', $context->driveId());
                        $locked = $store->lockTrashedRoot(Identity::key($node->getKey()), $threshold);
                        if ($locked === null) {
                            return 0;
                        }
                        $count = $nodes->subtree($locked)->count();
                        $purge->execute(context: $context, data: new PurgeNodeInput(node: $locked));

                        return $count;
                    });
                    if ($purged > 0) {
                        $purgedRoots++;
                        $purgedNodeCount += $purged;
                        $metrics->histogram(name: 'vaulter_purge_batch_nodes', value: $purged);
                    }
                } catch (Throwable $exception) {
                    $failed = true;
                    Log::error('Vaulter retention purge root failed.', [
                        'root_id' => $node->id,
                        'correlation_id' => $context?->correlationId,
                        'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
                    ]);
                    $this->error('Purge failed for trash root '.Identity::stringify($node->id).'.');
                }
            }
        });
        if ($purgedNodeCount > 0) {
            $metrics->counter(name: 'vaulter_purge_nodes_total', amount: $purgedNodeCount);
        }
        $this->info(sprintf('Purged %d trashed subtree%s.', $purgedRoots, $purgedRoots === 1 ? '' : 's'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
