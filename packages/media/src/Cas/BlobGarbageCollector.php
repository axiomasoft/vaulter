<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Throwable;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;
use Vaulter\Support\Config;

/**
 * Two-phase, race-safe blob GC (H.3). It is ACL-blind — liveness ≠ the viewer's
 * rights, so a node a user cannot see still pins its blob; GC runs as the system
 * with no panel/workspace context.
 *
 *  PHASE 1 (mark): scan committed ∧ ref_count(cache)=0 (reconciliation rebuilds
 *  that cache from the edge table, so a blob qualifies once its last edge is
 *  detached and reconciled), then under the per-(scope,sha256) lock RE-VERIFY no
 *  live edge exists in v_file_revision_blobs before tombstoning (grace deadline =
 *  now + grace, armed here so the sweep cannot delete within the grace window).
 *
 *  PHASE 2 (sweep): take tombstoned blobs past grace, RE-VERIFY under the lock
 *  (a resurrection may have flipped them back to committed), → deleting →
 *  physical delete (scope-qualified key, no cross-scope deletion) → deleted
 *  (idempotent tombstone). On PostgreSQL the sweep runs SERIALIZABLE with
 *  retry-on-40001.
 *
 * The advisory lock + UNIQUE (scope, sha256) + resurrection are what make this
 * safe against a concurrent upload-commit: whichever transaction wins the lock
 * acts on rows the other will then re-read; a commit resurrects before a sweep
 * can delete, a sweep that wins re-checks edges before deleting.
 */
final readonly class BlobGarbageCollector
{
    private const int BATCH_SIZE = 1000;

    private const int MAX_SERIALIZATION_RETRIES = 5;

    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private FileRevisionBlobStoreRepository $edges,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
    ) {}

    /**
     * Run both phases and return a per-phase tally.
     *
     * @return array{tombstoned: int, swept: int, skipped: int}
     */
    public function collect(int $graceHours = 0): array
    {
        $grace = Config::gcGraceHours($graceHours);

        $mark = $this->mark(graceHours: $grace);
        $sweep = $this->sweep();

        return [
            'tombstoned' => $mark['tombstoned'],
            'swept' => $sweep['swept'],
            'skipped' => $mark['skipped'] + $sweep['skipped'],
        ];
    }

    /**
     * PHASE 1 — tombstone committed blobs that are genuinely unreferenced.
     *
     * @return array{tombstoned: int, skipped: int}
     */
    public function mark(int $graceHours): array
    {
        $tombstoned = 0;
        $skipped = 0;

        $this->metrics->timing(
            name: 'vaulter_gc_duration_seconds',
            operation: function () use ($graceHours, &$tombstoned, &$skipped): void {
                foreach ($this->candidateBatches(static fn (Builder $q): Builder => $q->garbageCollectable()) as $batch) {
                    $marked = $this->markBatch($batch, $graceHours);
                    $tombstoned += $marked;
                    $skipped += $batch->count() - $marked;

                    $this->metrics->histogram(name: 'vaulter_gc_batch_rows', value: $batch->count());
                    $this->metrics->counter(name: 'vaulter_gc_rows_scanned_total', amount: $batch->count());
                }
            },
            labels: ['phase' => 'mark'],
        );

        return ['tombstoned' => $tombstoned, 'skipped' => $skipped];
    }

    /**
     * PHASE 2 — sweep tombstoned blobs past their grace and physically delete.
     *
     * @return array{swept: int, skipped: int}
     */
    public function sweep(): array
    {
        $swept = 0;
        $skipped = 0;
        $hadBatch = false;

        $this->metrics->timing(
            name: 'vaulter_gc_duration_seconds',
            operation: function () use (&$swept, &$skipped, &$hadBatch): void {
                foreach ($this->candidateBatches(static fn (Builder $q): Builder => $q->sweepable()) as $batch) {
                    $hadBatch = true;
                    $batchSwept = 0;

                    foreach ($batch as $candidate) {
                        $deleted = $this->serializable(fn (): bool => $this->lockedTransaction($candidate, function (Blob $blob): bool {
                            // (the body runs inside a SERIALIZABLE transaction on PG — see
                            // serializable()/lockedTransaction(): the isolation level is set as
                            // the FIRST statement inside the transaction, before the lock.)
                            // A resurrection between scan and lock flips it back to committed —
                            // never delete then.
                            if ($blob->status !== BlobStatus::Tombstoned) {
                                return false;
                            }

                            if ($this->edges->hasLiveEdge($blob)) {
                                return false;
                            }

                            if ($blob->delete_after_at === null || $blob->delete_after_at->isFuture()) {
                                return false;
                            }

                            // Claim it, delete the scope-qualified object, finalise the
                            // idempotent tombstone. A missing object is fine — markDeleted is
                            // idempotent.
                            $this->blobs->markDeleting($blob);
                            $this->content->delete($blob->storage_key, $blob->disk);
                            $this->blobs->markDeleted($blob);

                            return true;
                        }, serializable: true));

                        $deleted ? $batchSwept++ : $skipped++;
                    }

                    $swept += $batchSwept;
                    $this->metrics->histogram(name: 'vaulter_gc_batch_rows', value: $batch->count());
                    $this->metrics->counter(name: 'vaulter_gc_objects_deleted_total', amount: $batchSwept);
                }
            },
            labels: ['phase' => 'sweep'],
        );

        if (!$hadBatch) {
            $this->metrics->counter(name: 'vaulter_gc_objects_deleted_total', amount: 0);
        }

        return ['swept' => $swept, 'skipped' => $skipped];
    }

    /**
     * Sweep PENDING blobs from a crashed staging (H.3): a pending row older than
     * the grace window whose commit never finished and that carries no edge. Its
     * (possibly partial) object is deleted and the row is finalised as a deleted
     * tombstone, all under the lock so a slow-but-live commit is not stolen.
     *
     * @return array{swept: int, skipped: int}
     */
    public function sweepPending(int $graceHours = 0): array
    {
        $grace = Config::gcGraceHours($graceHours);
        $cutoff = now()->subHours($grace);
        $swept = 0;
        $skipped = 0;

        $batches = $this->candidateBatches(static fn (Builder $q): Builder => $q
            ->where('status', BlobStatus::Pending->value)
            ->where('first_seen_at', '<', $cutoff));

        foreach ($batches as $batch) {
            foreach ($batch as $candidate) {
                // Symmetry with sweep() (F-P1.5-02): the pending-sweep runs its unit
                // SERIALIZABLE on PostgreSQL with retry-on-40001, so a transient
                // serialization failure under concurrent GC is retried rather than
                // propagated as a QueryException that aborts the whole pass.
                $acted = $this->serializable(fn (): bool => $this->lockedTransaction($candidate, function (Blob $blob) use ($cutoff): bool {
                    if ($blob->status !== BlobStatus::Pending) {
                        return false;
                    }

                    // A pending row that grew an edge (a commit landing right now) is
                    // live — leave it.
                    if ($this->edges->hasLiveEdge($blob)) {
                        return false;
                    }

                    if ($blob->first_seen_at !== null && $blob->first_seen_at->greaterThanOrEqualTo($cutoff)) {
                        return false;
                    }

                    $this->blobs->markDeleting($blob);
                    $this->content->delete($blob->storage_key, $blob->disk);
                    $this->blobs->markDeleted($blob);

                    return true;
                }, serializable: true));

                $acted ? $swept++ : $skipped++;
            }

            $this->metrics->histogram(name: 'vaulter_gc_batch_rows', value: $batch->count());
        }

        return ['swept' => $swept, 'skipped' => $skipped];
    }

    /**
     * Run a single-blob critical section inside a transaction whose first
     * statement is the per-(scope,sha256) lock, re-reading the row FOR UPDATE on
     * MySQL. The callback receives the locked, fresh row and returns whether it
     * acted.
     *
     * When $serializable is set on PostgreSQL the isolation level is raised as the
     * VERY FIRST statement inside the open transaction block — the only place
     * `SET TRANSACTION` takes effect on PG (issued outside a block it warns and is
     * ignored). It precedes even the advisory lock, which is allowed: SET
     * TRANSACTION only forbids a prior data statement, not a prior SET.
     *
     * The raise is skipped when this is NOT a top-level transaction (transactionLevel
     * > 1) — i.e. the GC unit runs nested inside a caller's open transaction (a host
     * wrapping the sweep, or RefreshDatabase in tests). PostgreSQL forbids changing the
     * isolation level once a transaction has issued a data statement, so SET would 25001;
     * the only valid behaviour is to inherit the enclosing transaction's isolation. The
     * advisory lock + FOR UPDATE still serialise the critical section either way.
     *
     * @param \Closure(Blob): bool $work
     */
    private function lockedTransaction(Blob $candidate, \Closure $work, bool $serializable = false): bool
    {
        return $this->database->transaction(function () use ($candidate, $work, $serializable): bool {
            if ($serializable && $this->lock->isPostgres() && $this->database->connection()->transactionLevel() <= 1) {
                $this->database->connection()->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }

            $this->lock->acquire(scope: $this->scopeFor($candidate), sha256: $candidate->sha256);

            $fresh = $this->blobs->lockForUpdate($candidate);

            return $work($fresh);
        });
    }

    /**
     * On PostgreSQL run the unit SERIALIZABLE and retry on a 40001 serialization
     * failure (H.3) — the isolation level itself is raised INSIDE the transaction
     * (see lockedTransaction), this only drives the retry loop. On MySQL/SQLite the
     * FOR-UPDATE/single-writer path already serialises, so run once.
     *
     * @param \Closure(): bool $unit
     */
    private function serializable(\Closure $unit): bool
    {
        if (!$this->lock->isPostgres()) {
            return $unit();
        }

        $attempt = 0;

        while (true) {
            try {
                return $unit();
            } catch (QueryException $exception) {
                $attempt++;

                if (!$this->isSerializationFailure($exception) || $attempt >= self::MAX_SERIALIZATION_RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    private function isSerializationFailure(Throwable $exception): bool
    {
        // PostgreSQL serialization_failure → SQLSTATE 40001.
        return str_contains($exception->getMessage(), '40001');
    }

    /**
     * The scope discriminator for a blob's advisory lock — the same segment baked
     * into its storage_key, so GC and commit lock the same liveness unit.
     */
    private function scopeFor(Blob $blob): string
    {
        return $blob->scope_key;
    }

    /**
     * Lock before reading state/liveness, but amortise the transaction and reads
     * over a bounded batch. Keep repository writes so model events still fire.
     *
     * @param Collection<int, Blob> $batch
     */
    private function markBatch(Collection $batch, int $graceHours): int
    {
        $model = Config::blobModel();

        // Custom models/observers may change another candidate while saving.
        // Preserve the per-row fresh liveness check for those extension points.
        if ($model !== Blob::class || $this->hasBlobListeners()) {
            $marked = 0;

            foreach ($batch as $candidate) {
                $acted = $this->lockedTransaction($candidate, function (Blob $blob) use ($graceHours): bool {
                    if ($blob->status !== BlobStatus::Committed || $this->edges->hasLiveEdge($blob)) {
                        return false;
                    }

                    $this->blobs->tombstone(blob: $blob, graceHours: $graceHours);

                    return true;
                });

                $marked += (int) $acted;
            }

            return $marked;
        }

        return $this->database->transaction(function () use ($batch, $graceHours): int {
            if ($this->lock->isPostgres()) {
                $keys = array_values($batch->map(fn (Blob $blob): string => 'cas:'.$this->scopeFor($blob).':'.$blob->sha256)->all());

                $this->lock->acquireMany($keys);
            }

            $model = Config::blobModel();
            $query = $model::query()->whereKey($batch->modelKeys())->orderBy((new $model)->getKeyName());

            if ($this->lock->usesRowLock()) {
                $query->forceIndex('PRIMARY')->lockForUpdate();
            }

            $fresh = $query->get();
            $edgeModel = Config::fileVersionBlobModel();
            /** @var \Illuminate\Support\Collection<int, int|string> $liveIds */
            $liveIds = $edgeModel::query()->whereIn('blob_id', $batch->modelKeys())->distinct()->pluck('blob_id');
            $live = array_fill_keys($liveIds->all(), true);
            $marked = 0;

            foreach ($fresh as $blob) {
                if ($blob->status !== BlobStatus::Committed) {
                    continue;
                }

                if (isset($live[$blob->id])) {
                    continue;
                }

                $this->blobs->tombstone(blob: $blob, graceHours: $graceHours);
                $marked++;
            }

            return $marked;
        });
    }

    private function hasBlobListeners(): bool
    {
        $dispatcher = Blob::getEventDispatcher();

        return array_any(
            ['retrieved', 'saving', 'saved', 'updating', 'updated'],
            static fn (string $event): bool => $dispatcher?->hasListeners('eloquent.'.$event.': '.Blob::class) ?? false,
        );
    }

    /**
     * Scan by key, never offset: processed and skipped rows both advance the
     * checkpoint even though status changes remove rows from the candidate set.
     * A phase-start high-water key keeps inserts from extending a pass forever.
     * Queries and materialised batches hold at most BATCH_SIZE rows, as the
     * system (no panel/workspace — GC is ACL-blind).
     *
     * @param  \Closure(Builder<Blob>): Builder<Blob> $scope
     * @return iterable<Collection<int, Blob>>
     */
    private function candidateBatches(\Closure $scope): iterable
    {
        $model = Config::blobModel();
        $query = $scope($model::query());
        $key = $query->getModel()->getKeyName();
        $upper = (clone $query)->reorder($key, 'desc')->value($key);

        if ($upper === null) {
            return;
        }

        $last = null;

        do {
            $page = (clone $query)->where($key, '<=', $upper)->reorder($key)->limit(self::BATCH_SIZE);

            if ($last !== null) {
                $page->where($key, '>', $last);
            }

            $batch = $page->get();

            if ($batch->isEmpty()) {
                return;
            }

            $last = $batch->last()->getKey();

            yield $batch;
        } while ($batch->count() === self::BATCH_SIZE);
    }
}
