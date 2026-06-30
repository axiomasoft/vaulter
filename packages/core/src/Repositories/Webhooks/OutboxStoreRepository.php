<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Support\Config;

/**
 * The write side of transactional-outbox row work (master plan §2.11 / D14 / M3b).
 * Today it carries only the RETENTION prune: a SETTLED outbox row — status
 * 'dispatched' with its dispatched_at stamped — past the retention window is audit
 * history that may be reclaimed. It opens NO transaction (the caller decides) and
 * NEVER touches a 'pending' row: those are in-flight / due and the relay still owns
 * them.
 */
final readonly class OutboxStoreRepository
{
    /**
     * The settled-row predicate, applied identically by both the count and the
     * delete so a dry-run reports exactly what a real run would remove: only
     * status 'dispatched' rows whose dispatched_at is at or before the threshold.
     * A 'pending' row can never match — it has no terminal dispatched_at and the
     * status guard excludes it outright.
     *
     * @param  Builder<Outbox> $query
     * @return Builder<Outbox>
     */
    private function settledBefore(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->where('status', 'dispatched')
            ->where('dispatched_at', '<=', $threshold);
    }

    /**
     * How many settled outbox rows are past the retention window — the dry-run
     * count. Pure read of the same predicate the prune deletes.
     */
    public function countDispatchedBefore(Carbon $threshold): int
    {
        return $this->settledBefore(query: $this->newQuery(), threshold: $threshold)->count();
    }

    /**
     * Delete settled outbox rows past the retention window in portable, bounded
     * batches and return the number removed.
     *
     * Portability: PostgreSQL has no `DELETE ... LIMIT`, so each round SELECTs up to
     * $chunk primary keys matching the settled predicate, then deletes exactly that
     * id set via whereKey(). The status+time predicate is re-evaluated on every
     * round, so a row that a concurrent relay transitions can never widen the set
     * mid-prune. The loop stops once a round returns fewer keys than $chunk (the
     * tail), bounding the working set regardless of backlog size.
     */
    public function pruneDispatchedBefore(Carbon $threshold, int $chunk = 1000): int
    {
        $batch = max($chunk, 1);
        $deleted = 0;

        do {
            $ids = $this->settledBefore(query: $this->newQuery(), threshold: $threshold)
                ->limit($batch)
                ->pluck($this->keyName())
                ->all();

            if ($ids === []) {
                break;
            }

            // Delete the just-selected ids via the base query builder: its delete()
            // returns a typed int row count, and these models are hard-deletable (no
            // SoftDeletes scope to route around), so the base delete is the real
            // physical delete, not a soft-delete.
            $deleted += $this->newQuery()->whereKey($ids)->toBase()->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }

    private function keyName(): string
    {
        $model = Config::outboxModel();

        return (new $model)->getKeyName();
    }

    /**
     * @return Builder<Outbox>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<Outbox> $model */
        $model = Config::outboxModel();

        return $model::query();
    }
}
