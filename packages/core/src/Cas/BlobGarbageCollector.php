<?php

declare(strict_types=1);

namespace Vaulter\Cas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;
use Vaulter\Contracts\ContentStore;
use Vaulter\Enums\BlobStatus;
use Vaulter\Models\Blob;
use Vaulter\Repositories\BlobStoreRepository;
use Vaulter\Repositories\FileVersionBlobStoreRepository;
use Vaulter\Support\Config;

/**
 * Two-phase, race-safe blob GC (H.3). It is ACL-blind — liveness ≠ the viewer's
 * rights, so a node a user cannot see still pins its blob; GC runs as the system
 * with no panel/workspace context.
 *
 *  PHASE 1 (mark): scan committed ∧ ref_count(cache)=0 (reconciliation rebuilds
 *  that cache from the edge table, so a blob qualifies once its last edge is
 *  detached and reconciled), then under the per-(scope,sha256) lock RE-VERIFY no
 *  live edge exists in v_file_version_blobs before tombstoning (grace deadline =
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
    private const int MAX_SERIALIZATION_RETRIES = 5;

    public function __construct(
        private ContentStore $content,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private FileVersionBlobStoreRepository $edges,
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

        foreach ($this->candidates(static fn (Builder $q): Builder => $q->garbageCollectable()) as $candidate) {
            $acted = $this->lockedTransaction($candidate, function (Blob $blob) use ($graceHours): bool {
                // Re-read under the lock; the cheap scan may be stale.
                if ($blob->status !== BlobStatus::Committed) {
                    return false;
                }

                // The liveness re-check that makes online GC safe: an edge created
                // after the scan (but before the lock) keeps the blob alive.
                if ($this->edges->hasLiveEdge($blob)) {
                    return false;
                }

                $this->blobs->tombstone(blob: $blob, graceHours: $graceHours);

                return true;
            });

            $acted ? $tombstoned++ : $skipped++;
        }

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

        foreach ($this->candidates(static fn (Builder $q): Builder => $q->sweepable()) as $candidate) {
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

            $deleted ? $swept++ : $skipped++;
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

        $candidates = $this->candidates(static fn (Builder $q): Builder => $q
            ->where('status', BlobStatus::Pending->value)
            ->where('first_seen_at', '<', $cutoff));

        foreach ($candidates as $candidate) {
            $acted = $this->lockedTransaction($candidate, function (Blob $blob) use ($cutoff): bool {
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
            });

            $acted ? $swept++ : $skipped++;
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
     * @param \Closure(Blob): bool $work
     */
    private function lockedTransaction(Blob $candidate, \Closure $work, bool $serializable = false): bool
    {
        return DB::transaction(function () use ($candidate, $work, $serializable): bool {
            if ($serializable && $this->lock->isPostgres()) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
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
        return match ($blob->dedup_scope) {
            'storage' => 's'.($blob->storage_id ?? 'null'),
            'tenant' => 't'.($blob->tenant_id ?? 'null'),
            default => 'global',
        };
    }

    /**
     * Stream the GC/sweep candidates for the given scope, as the system (no panel/
     * workspace — GC is ACL-blind).
     *
     * @param  \Closure(Builder<Blob>): Builder<Blob> $scope
     * @return iterable<Blob>
     */
    private function candidates(\Closure $scope): iterable
    {
        $model = Config::blobModel();

        return $scope($model::query())->cursor();
    }
}
