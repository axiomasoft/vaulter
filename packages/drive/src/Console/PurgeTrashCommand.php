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
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
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

    public function handle(NodeReadRepository $nodes, NodeStoreRepository $store, PurgeNodeAction $purge, MetricsRecorder $metrics, OperationRunner $runner): int
    {
        return Tenancy::without(fn (): int => $this->runMaintenance($nodes, $store, $purge, $metrics, $runner));
    }

    private function runMaintenance(NodeReadRepository $nodes, NodeStoreRepository $store, PurgeNodeAction $purge, MetricsRecorder $metrics, OperationRunner $runner): int
    {
        $purgedRoots = 0;
        $purgedNodeCount = 0;
        $skipped = 0;
        $failed = false;
        $metrics->timing(name: 'vaulter_purge_duration_seconds', operation: function () use (
            $nodes, $store, $purge, $metrics, $runner, &$purgedRoots, &$purgedNodeCount, &$skipped, &$failed,
        ): void {
            $candidates = $nodes->trashedBefore(Carbon::now())->get();
            foreach ($candidates as $node) {
                $context = null;
                try {
                    $drive = $node->storage()->first();
                    if ($drive === null) {
                        continue;
                    }
                    $context = DriveContext::system(drive: $drive, reason: 'retention');
                    $override = $this->option('days');
                    $days = (is_string($override) || is_int($override)) && is_numeric($override)
                        ? max((int) $override, 0)
                        : ($this->profiles->get($drive->profile)->getTrashRetentionDays() ?? $this->driveConfig->trashRetentionDays());
                    $threshold = Carbon::now()->subDays($days);
                    // Each root is one root operation `drive.trash.purge` (D48, I9): the documents drain runs before its
                    // transaction, and a root that stays refused (document_state_pending) rolls back only itself.
                    $purged = $runner->write(new OperationSpec('drive.trash.purge', new ModuleId('drive')), $context->operation, function () use ($node, $context, $threshold, $nodes, $store, $purge): int {
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
                    $skipped++;
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
        if ($purgedRoots > 0) {
            $metrics->counter(name: 'vaulter_purge_roots_total', amount: $purgedRoots, labels: ['outcome' => 'purged']);
        }
        if ($skipped > 0) {
            $metrics->counter(name: 'vaulter_purge_roots_total', amount: $skipped, labels: ['outcome' => 'skipped']);
        }
        $this->info(sprintf('Purged %d trashed subtree%s.', $purgedRoots, $purgedRoots === 1 ? '' : 's').($skipped > 0 ? sprintf(' Skipped %d.', $skipped) : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
