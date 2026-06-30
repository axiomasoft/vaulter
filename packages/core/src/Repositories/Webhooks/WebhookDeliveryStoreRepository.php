<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Support\Config;

/**
 * The write side of webhook-delivery row work (master plan §2.11 / D14 / M3b).
 * Today it carries only the RETENTION prune: a TERMINAL delivery — status
 * 'delivered' or 'dead' (the DLQ) — never transitions again, so once it is past the
 * retention window it is audit history that may be reclaimed. The terminalisation
 * time is the row's updated_at (a terminal row is never re-saved). It opens NO
 * transaction and NEVER touches a 'pending'/'failed' row: those are in-flight /
 * retryable and the relay's due-retry sweep still owns them.
 */
final readonly class WebhookDeliveryStoreRepository
{
    /**
     * The terminal-row predicate, applied identically by both the count and the
     * delete so a dry-run reports exactly what a real run would remove: status in
     * delivered|dead whose updated_at (the terminalisation time) is at or before the
     * threshold. A 'pending'/'failed' row can never match — the status guard excludes
     * the retryable states outright.
     *
     * @param  Builder<WebhookDelivery> $query
     * @return Builder<WebhookDelivery>
     */
    private function terminalBefore(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->whereIn('status', ['delivered', 'dead'])
            ->where('updated_at', '<=', $threshold);
    }

    /**
     * How many terminal deliveries are past the retention window — the dry-run
     * count. Pure read of the same predicate the prune deletes.
     */
    public function countTerminalBefore(Carbon $threshold): int
    {
        return $this->terminalBefore(query: $this->newQuery(), threshold: $threshold)->count();
    }

    /**
     * Delete terminal deliveries past the retention window in portable, bounded
     * batches and return the number removed.
     *
     * Portability: PostgreSQL has no `DELETE ... LIMIT`, so each round SELECTs up to
     * $chunk primary keys matching the terminal predicate, then deletes exactly that
     * id set via whereKey(). The status+time predicate is re-evaluated on every
     * round, so a delivery that a concurrent retry transitions can never widen the
     * set mid-prune. The loop stops once a round returns fewer keys than $chunk (the
     * tail), bounding the working set regardless of backlog size.
     */
    public function pruneTerminalBefore(Carbon $threshold, int $chunk = 1000): int
    {
        $batch = max($chunk, 1);
        $deleted = 0;

        do {
            $ids = $this->terminalBefore(query: $this->newQuery(), threshold: $threshold)
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
        $model = Config::webhookDeliveryModel();

        return (new $model)->getKeyName();
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<WebhookDelivery> $model */
        $model = Config::webhookDeliveryModel();

        return $model::query();
    }
}
