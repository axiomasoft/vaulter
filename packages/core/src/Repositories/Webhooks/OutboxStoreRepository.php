<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;

/**
 * The write side of transactional-outbox row work (master plan §2.11 / D14 / M3b).
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

    /**
     * Legacy rows whose fan-out cannot be proven: settled by the pre-1.0 relay, which
     * marked a row before writing its deliveries, and without any delivery now. They
     * are either events nobody subscribed to or fan-outs lost to a crash — the data
     * cannot tell which. Oldest first, optionally within [$since, $until].
     *
     * @return Builder<Outbox>
     */
    public function ambiguousLegacy(?Carbon $since = null, ?Carbon $until = null): Builder
    {
        /** @var class-string<WebhookDelivery> $deliveryModel */
        $deliveryModel = $this->vaulterConfig->model('webhook_delivery');
        $query = $this->newQuery();
        $outbox = $query->getModel();
        $deliveries = (new $deliveryModel)->getTable();

        return $query
            ->where('status', 'completed')
            ->where('legacy_fanout', true)
            ->where('delivery_count', 0)
            ->whereNotExists(static fn (QueryBuilder $delivery): QueryBuilder => $delivery
                ->selectRaw('1')
                ->from($deliveries)
                ->whereColumn($deliveries.'.event_id', $outbox->qualifyColumn('event_id')))
            ->when($since !== null, static fn (Builder $range): Builder => $range->where('created_at', '>=', $since))
            ->when($until !== null, static fn (Builder $range): Builder => $range->where('created_at', '<=', $until))
            ->orderBy('created_at')
            ->orderBy($outbox->getQualifiedKeyName());
    }

    /**
     * Put the given legacy rows back in the relay queue: pending, no lease, no
     * checkpoint, no legacy mark. The relay then fans them out to the endpoints
     * eligible now (including ones inactive when the event happened); a pair that
     * already has a delivery is skipped. Only rows still matching ambiguousLegacy()
     * are touched, so a repeated replay changes nothing.
     *
     * @param list<int|string> $ids
     */
    public function replayLegacy(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->ambiguousLegacy()->reorder()->whereKey($ids)->toBase()->update([
            'status' => 'pending',
            'legacy_fanout' => false,
            'fanout_cursor' => null,
            'fanned_out_at' => null,
            'claim_token' => null,
            'claim_until' => null,
            'updated_at' => now(),
        ]);
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
