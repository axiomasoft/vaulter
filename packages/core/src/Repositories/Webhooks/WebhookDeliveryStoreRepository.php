<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * The write side of webhook-delivery row work (master plan §2.11 / D14 / M3b).
 * Today it carries the RETENTION prune: a TERMINAL delivery — status 'delivered'
 * or 'dead' (the DLQ) — past the retention window is history that may be reclaimed.
 * Manual redelivery can re-arm it, so deletion rechecks both the terminal status
 * and updated_at (the latest terminalisation time). It opens NO
 * transaction and NEVER touches a 'pending'/'failed' row: those are in-flight /
 * retryable and the relay's due-retry sweep still owns them.
 */
final readonly class WebhookDeliveryStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

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
            // Redelivery can re-arm or finish the row again after the batch SELECT.
            // Recheck status and age at deletion so its fresh work survives.
            $deleted += $this->terminalBefore(query: $this->newQuery(), threshold: $threshold)->whereKey($ids)->toBase()->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }

    private function keyName(): string
    {
        $model = $this->vaulterConfig->model('webhook_delivery');

        return (new $model)->getKeyName();
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<WebhookDelivery> $model */
        $model = $this->vaulterConfig->model('webhook_delivery');

        return $model::query();
    }

    /**
     * Finish every delivery of an endpoint that is still waiting or retrying as dead.
     * Used when the endpoint is switched off: nothing may be sent to it afterwards, and
     * dropping the claim fences a job that is mid-flight so it cannot settle the row.
     */
    public function killOpenForEndpoint(int|string $endpointId, string $reason): int
    {
        return $this->newQuery()
            ->where('endpoint_id', $endpointId)
            ->whereIn('status', ['pending', 'failed'])
            ->toBase()
            ->update([
                'status' => 'dead',
                'next_retry_at' => null,
                'claim_token' => null,
                'claim_until' => null,
                'last_error' => $reason,
                'updated_at' => now(),
            ]);
    }

    /**
     * An endpoint's deliveries, newest first, for the admin API.
     *
     * @return CursorPaginator<int, WebhookDelivery>
     */
    public function pageForEndpoint(WebhookEndpoint $endpoint, ?string $cursor, int $perPage): CursorPaginator
    {
        return $this->newQuery()
            ->where('endpoint_id', $endpoint->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(perPage: $perPage, cursor: $cursor);
    }

    /** One delivery of the endpoint; a delivery of another endpoint is not found. */
    public function findForEndpoint(WebhookEndpoint $endpoint, int|string $deliveryId): ?WebhookDelivery
    {
        return $this->newQuery()->where('endpoint_id', $endpoint->getKey())->whereKey($deliveryId)->first();
    }
}
