<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Webhooks\Outbox;

/**
 * The write side of transactional-outbox row work.
 * Today it carries only the RETENTION prune: a COMPLETED outbox row — every
 * eligible endpoint already has its delivery, fanned_out_at stamped — past the
 * retention window is audit history that may be reclaimed. It opens NO transaction
 * (the caller decides) and NEVER touches a 'pending' row, however old: its fan-out
 * is unfinished and deleting it would lose the remaining deliveries for good.
 */
final readonly class OutboxStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * The finished-row predicate, applied identically by both the count and the
     * delete so a dry-run reports exactly what a real run would remove: only
     * status 'completed' rows whose fanned_out_at is at or before the threshold.
     * A 'pending' row can never match — the status guard excludes it outright.
     *
     * @param  Builder<Outbox> $query
     * @return Builder<Outbox>
     */
    private function completedBefore(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->where('status', 'completed')
            ->where('fanned_out_at', '<=', $threshold);
    }

    /**
     * How many completed outbox rows are past the retention window — the dry-run
     * count. Pure read of the same predicate the prune deletes.
     */
    public function countCompletedBefore(Carbon $threshold): int
    {
        return $this->completedBefore(query: $this->newQuery(), threshold: $threshold)->count();
    }

    /**
     * Delete completed outbox rows past the retention window in portable, bounded
     * batches and return the number removed.
     *
     * Portability: PostgreSQL has no `DELETE ... LIMIT`, so each round SELECTs up to
     * $chunk primary keys matching the completed predicate, then deletes exactly that
     * id set via whereKey(). The status+time predicate is re-evaluated on every
     * round, so a row that a concurrent relay transitions can never widen the set
     * mid-prune. The loop stops once a round returns fewer keys than $chunk (the
     * tail), bounding the working set regardless of backlog size.
     */
    public function pruneCompletedBefore(Carbon $threshold, int $chunk = 1000): int
    {
        $batch = max($chunk, 1);
        $deleted = 0;

        do {
            $ids = $this->completedBefore(query: $this->newQuery(), threshold: $threshold)
                ->limit($batch)
                ->pluck($this->keyName())
                ->all();

            if ($ids === []) {
                break;
            }

            // A row a replay turned back to pending after the select is no longer completed
            // and must survive, so the delete repeats the completion predicate.
            // Delete the just-selected ids via the base query builder: its delete()
            // returns a typed int row count, and these models are hard-deletable (no
            // SoftDeletes scope to route around), so the base delete is the real
            // physical delete, not a soft-delete.
            $deleted += $this->completedBefore(query: $this->newQuery(), threshold: $threshold)->whereKey($ids)->toBase()->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }

    private function keyName(): string
    {
        $model = $this->vaulterConfig->model('outbox');

        return (new $model)->getKeyName();
    }

    /**
     * @return Builder<Outbox>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<Outbox> $model */
        $model = $this->vaulterConfig->model('outbox');

        return $model::query();
    }
}
