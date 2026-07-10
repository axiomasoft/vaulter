<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Cas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Vaulter\Cas\BlobLock;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Cas\Blob;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_blobs (H.3). It owns the locked find-or-create by the
 * per-scope dedup key, resurrection of a tombstoned match, and the blob state
 * transitions. It opens NO transactions — BlobStore wraps every call in one — and
 * makes NO liveness decision beyond reading the row under the lock the caller
 * already took. The dedup column set mirrors the dedup-UNIQUE index exactly.
 */
final readonly class BlobStoreRepository
{
    public function __construct(
        private BlobLock $lock,
    ) {}

    /**
     * Find-or-create the committed blob for a content address inside the caller's
     * already-locked transaction (H.3). Returns the existing row on a dedup hit
     * (resurrecting it if tombstoned) or a freshly created committed row, plus a
     * flag telling BlobStore whether bytes must be promoted (true ⇒ newly created,
     * promote; false ⇒ dedup hit, skip the physical write).
     *
     * The UNIQUE (scope, sha256) constraint is the portable race backbone for a
     * NON-NULL scope value: a concurrent INSERT loser catches the unique violation
     * and re-SELECTs. CAVEAT (F-P1.5-03 / D18): for scope=tenant with a NULL
     * tenant_id (single-tenant / tenancy disabled — the common case) this UNIQUE
     * does NOT fire — SQL never treats two NULLs as equal — so the true backbone
     * for that case is `storage_key_uniq` (single-column, NULL-free by
     * construction) on `v_blobs.storage_key`; see PanelTablesBuilder::defineBlobs()
     * and ADR-0012 Amendment.
     *
     * @return array{blob: Blob, created: bool}
     */
    public function findOrCreate(
        string $sha256,
        int $sizeBytes,
        string $storageKey,
        string $disk,
        string $dedupScope,
        ?int $tenantId,
        ?int $storageId,
    ): array {
        $existing = $this->lockedLookup(sha256: $sha256, tenantId: $tenantId, storageId: $storageId, dedupScope: $dedupScope);

        if ($existing !== null) {
            $this->resurrectIfTombstoned($existing);

            return ['blob' => $existing, 'created' => false];
        }

        try {
            $blob = $this->insertCommitted(
                sha256: $sha256,
                sizeBytes: $sizeBytes,
                storageKey: $storageKey,
                disk: $disk,
                dedupScope: $dedupScope,
                tenantId: $tenantId,
                storageId: $storageId,
            );

            return ['blob' => $blob, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // We lost the INSERT race (another committer of the same bytes won).
            // Re-SELECT under the same transaction and reuse its row — never treat
            // a unique violation as fatal (H.3). This is the loser branch of the
            // UNIQUE (scope, sha256) backbone.
            $winner = $this->lockedLookup(sha256: $sha256, tenantId: $tenantId, storageId: $storageId, dedupScope: $dedupScope);

            if ($winner === null) {
                throw new \RuntimeException("Lost the blob INSERT race for {$sha256} but the winning row is not visible.");
            }

            $this->resurrectIfTombstoned($winner);

            return ['blob' => $winner, 'created' => false];
        }
    }

    /**
     * Mark a committed, unreferenced blob as tombstoned with its grace deadline
     * (GC phase 1). Caller holds the per-(scope,sha256) lock and has re-verified
     * no live edge.
     */
    public function tombstone(Blob $blob, int $graceHours): void
    {
        $blob->forceFill([
            'status' => BlobStatus::Tombstoned,
            'ref_count' => 0,
            'delete_after_at' => now()->addHours($graceHours),
        ])->save();
    }

    /**
     * Claim a tombstoned blob for the sweep (Tombstoned → Deleting). Caller holds
     * the lock and re-verified the tombstone is still unreferenced past grace.
     */
    public function markDeleting(Blob $blob): void
    {
        $blob->forceFill(['status' => BlobStatus::Deleting])->save();
    }

    /**
     * Finalise the sweep (Deleting → Deleted) — an idempotent tombstone row; the
     * bytes are already gone. delete_after_at is cleared so the row drops out of
     * every sweep scan.
     */
    public function markDeleted(Blob $blob): void
    {
        $blob->forceFill([
            'status' => BlobStatus::Deleted,
            'delete_after_at' => null,
        ])->save();
    }

    /**
     * Overwrite the ref_count CACHE from a freshly counted edge total
     * (reconciliation). This is the only writer of ref_count — commit never
     * touches it (H.3, no hot-row).
     */
    public function setRefCount(Blob $blob, int $count): void
    {
        $blob->forceFill(['ref_count' => $count])->save();
    }

    /**
     * Look up the blob owning a canonical object by its storage_key — the
     * row-existence probe the orphan disk-walk runs for each on-disk object (bytes
     * with NO row are the orphan candidates). $lock takes the MySQL row lock so the
     * orphan reconciler can RE-CHECK under the per-(scope,sha256) advisory lock that
     * no row materialised since the cheap scan (a concurrent commit may have just
     * created it). Any status counts as "owned" — a committed/pending row pins its
     * bytes, a dangling/tombstoned row is the row-side reconcile/GC's job, never the
     * orphan sweep's.
     *
     * The probe is DISK-AGNOSTIC by default ($disk = null): storage_key is GLOBALLY
     * UNIQUE (its own single-column index, NOT a composite with disk), so a
     * canonical object has at most one owning row pinned to whatever disk THAT row
     * records — which may differ from the disk currently being scanned. Filtering by
     * the scan disk would read such a cross-disk-owned object as row-less and prune
     * its LIVE bytes. Pass an explicit $disk only when a disk-qualified probe is
     * genuinely intended.
     */
    public function findByStorageKey(string $storageKey, ?string $disk = null, bool $lock = false): ?Blob
    {
        $query = $this->query()->where('storage_key', $storageKey);

        if ($disk !== null) {
            $query->where('disk', $disk);
        }

        if ($lock && $this->lock->usesRowLock()) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Re-read a single blob FOR UPDATE on MySQL (the row lock that stands in for
     * PG's advisory lock); a plain reload elsewhere. Used by the GC to re-verify
     * state under the lock.
     */
    public function lockForUpdate(Blob $blob): Blob
    {
        $query = $this->query()->whereKey($blob->getKey());

        if ($this->lock->usesRowLock()) {
            $query->lockForUpdate();
        }

        /** @var Blob $fresh */
        $fresh = $query->firstOrFail();

        return $fresh;
    }

    /**
     * Look up the blob for a content address within its dedup scope, taking the
     * MySQL row lock when that driver is the lock mechanism.
     */
    private function lockedLookup(string $sha256, ?int $tenantId, ?int $storageId, string $dedupScope): ?Blob
    {
        $query = $this->query()->where('sha256', $sha256);

        $this->applyScope(query: $query, dedupScope: $dedupScope, tenantId: $tenantId, storageId: $storageId);

        if ($this->lock->usesRowLock()) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Constrain a query to the dedup scope columns so the lookup matches the
     * dedup-UNIQUE index exactly: global ignores tenant/storage, tenant keys on
     * tenant_id, storage keys on storage_id.
     *
     * @param Builder<Blob> $query
     */
    private function applyScope(Builder $query, string $dedupScope, ?int $tenantId, ?int $storageId): void
    {
        match ($dedupScope) {
            'storage' => $query->where('storage_id', $storageId),
            'tenant' => $query->where('tenant_id', $tenantId),
            default => $query, // global: sha256 alone is the address
        };
    }

    private function insertCommitted(
        string $sha256,
        int $sizeBytes,
        string $storageKey,
        string $disk,
        string $dedupScope,
        ?int $tenantId,
        ?int $storageId,
    ): Blob {
        // forceFill (P1.4/F-P1.5-04): Blob is intentionally fully guarded (CAS
        // integrity: sha256/storage_key are content-addressed) — this is the
        // sole trusted commit seam, never raw request input.
        $blob = new (Config::blobModel());
        $blob->forceFill([
            'tenant_id' => $dedupScope === 'storage' ? null : $tenantId,
            'storage_id' => $dedupScope === 'storage' ? $storageId : null,
            'hash_algo' => 'sha256',
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'disk' => $disk,
            'storage_key' => $storageKey,
            'status' => BlobStatus::Committed,
            'ref_count' => 0,
            'dedup_scope' => $dedupScope,
            'committed_at' => now(),
        ]);
        $blob->save();

        return $blob;
    }

    /**
     * Resurrection (H.3): a commit that finds a tombstoned blob un-tombstones it
     * (→ committed, clear delete_after_at) in the same locked transaction. A
     * deleting/deleted row is also resurrected — its bytes will be re-promoted by
     * BlobStore since the canonical object may already be gone.
     */
    private function resurrectIfTombstoned(Blob $blob): void
    {
        if ($blob->status === BlobStatus::Committed || $blob->status === BlobStatus::Pending) {
            return;
        }

        $blob->forceFill([
            'status' => BlobStatus::Committed,
            'delete_after_at' => null,
            'committed_at' => $blob->committed_at ?? now(),
        ])->save();
    }

    /**
     * @return Builder<Blob>
     */
    private function query(): Builder
    {
        $model = Config::blobModel();

        return $model::query();
    }
}
