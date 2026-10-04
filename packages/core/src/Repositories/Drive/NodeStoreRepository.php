<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Write-side row work for v_nodes (§2.2). Bare mutations only: create/update,
 * the single-UPDATE move, and the bulk subtree state changes (trash, restore,
 * permanent soft-delete). It opens NO transactions and dispatches NO events — the
 * Drive Actions wrap every call in VaulterDatabase::transaction and emit the domain event. The
 * subtree helpers take an already-materialised id list (the caller resolved it
 * via NodeReadRepository::subtree with withMaxDepth) and issue ONE bulk UPDATE.
 */
final class NodeStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Node
    {
        // forceFill (P1.4/F-P1.8-02): $attributes here is ALWAYS a server-built
        // literal array from a Drive Action (never raw request input reaching this
        // repository) — mass-assignment guarding belongs at the HTTP boundary
        // (Node::$guarded), not on this trusted internal seam.
        $model = Config::nodeModel();
        $node = new $model;
        $node->forceFill($attributes);
        $node->save();

        // Refresh so DB-default columns (size_bytes, is_pinned, inherit_grants,
        // position, panel) are populated in-memory — callers (copy, list, the
        // facade return) read a fully hydrated row, never a half-set new model.
        return $node->refresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Node $node, array $attributes): Node
    {
        $node->forceFill($attributes)->save();

        return $node;
    }

    /**
     * The §2.2 move: a single parent_id UPDATE on the moved node only — its
     * descendants keep their parent_id, so the subtree rides along untouched (the
     * whole point of adjacency over materialised paths).
     */
    public function move(Node $node, int|string|null $parentId): Node
    {
        $node->forceFill(['parent_id' => $parentId])->save();

        return $node;
    }

    public function rename(Node $node, string $name): Node
    {
        $node->forceFill(['name' => $name])->save();

        return $node;
    }

    /**
     * Soft-trash a whole subtree atomically (§2.10 triplet). Every node in the id
     * list gets trashed_at/trashed_by stamped in ONE bulk UPDATE; the subtree
     * ROOT additionally records trashed_from_parent_id so restore can re-home it.
     * Descendants keep parent_id untouched, so the shape is restored verbatim.
     *
     * @param Collection<int, int|string> $ids the subtree ids (root first or any order)
     */
    public function trashSubtree(
        Collection $ids,
        Node $root,
        int|string|null $actorId,
        Carbon $at,
    ): void {
        if ($ids->isEmpty()) {
            return;
        }

        $this->newQuery()
            ->whereKey($ids->all())
            ->update([
                'trashed_at' => $at,
                'trashed_by' => $actorId,
            ]);

        // The root remembers where it hung so restore can detect a vanished
        // parent and re-home to root deterministically.
        $this->newQuery()
            ->whereKey($root->getKey())
            ->update(['trashed_from_parent_id' => $root->parent_id]);
    }

    /**
     * Clear the trashed triplet across a subtree (§2.10 restore) in ONE bulk
     * UPDATE. The root's parent_id may have been re-homed by the Action before
     * this call; this only clears the trash stamps.
     *
     * @param Collection<int, int|string> $ids
     */
    public function restoreSubtree(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $this->newQuery()
            ->whereKey($ids->all())
            ->update([
                'trashed_at' => null,
                'trashed_by' => null,
                'trashed_from_parent_id' => null,
            ]);
    }

    /**
     * Permanently soft-delete a subtree (§2.10 stage 2: deleted_at tombstone). The
     * bytes are NOT touched — detaching the version↔blob edges (done by the Action
     * via the CAS) is what makes the blobs GC-eligible (M1b reclaims them).
     *
     * @param Collection<int, int|string> $ids
     */
    public function softDeleteSubtree(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $model = Config::nodeModel();
        $model::query()->whereKey($ids->all())->delete();
    }

    /**
     * Physically purge a subtree's rows (§2.10 stage 3, F-P1.12-02) — a genuine
     * DELETE, not the {@see softDeleteSubtree} tombstone. `withTrashed()` matches
     * rows already carrying a `deleted_at` tombstone (the caller is expected to
     * have run the stage-2 soft-delete on the same ids first, so the version↔blob
     * edges are detached and GC-eligible before the rows disappear); the query
     * builder's `forceDelete()` then bypasses the SoftDeletes scope for the
     * statement itself, issuing a real `DELETE`, so FK `cascadeOnDelete` on every
     * dependent table (files → file_versions → file_version_blobs, node_document_
     * versions, node_grants, node_shares, …) physically removes their rows too —
     * this is what makes "purge" actually purge, instead of leaving inline
     * document content live in the database forever.
     *
     * @param Collection<int, int|string> $ids
     */
    public function purgeSubtree(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $model = Config::nodeModel();
        $model::query()->withTrashed()->whereKey($ids->all())->forceDelete();
    }

    /**
     * Re-verify + row-lock the purge root at delete time (P2.2 review fix,
     * TOCTOU). `PurgeTrashCommand` materialises purge candidates before opening
     * a transaction; between that read and the transaction actually running, a
     * concurrent `POST nodes/{id}/restore` can clear `trashed_at` on the same
     * node (queue delay, a slow prior candidate in the same batch, or plain bad
     * timing). Because purge is now irreversible (ADR-0016 / F-P1.12-02), acting
     * on the stale pre-transaction node would physically delete live data the
     * user just restored — including anything they added after restoring, since
     * the purge transaction runs strictly after the restore commits.
     *
     * `lockForUpdate()` takes a row lock inside the caller's transaction, so a
     * concurrent restore's UPDATE on the same row blocks until this transaction
     * resolves (commit deletes the row so the restore affects zero rows;
     * rollback lets the restore proceed normally). The `trashed_at` predicate is
     * re-evaluated against the CURRENT row, not the pre-fetched one — a cleared
     * `trashed_at` (restored) or a threshold no longer satisfied means this
     * returns null and the caller must skip the purge for that subtree.
     */
    public function lockTrashedRoot(int|string|null $id, Carbon $threshold): ?Node
    {
        if ($id === null) {
            return null;
        }

        $model = Config::nodeModel();

        /** @var Node|null $node */
        $node = $model::query()
            ->whereKey($id)
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', $threshold)
            ->lockForUpdate()
            ->first();

        return $node;
    }

    /**
     * Lock live node rows for the rest of the transaction, in ascending id order so
     * two operations over overlapping nodes queue instead of deadlocking (D21 level 2:
     * after lockTree, before aggregate and quota locks). SQLite has no row locks and
     * serialises writers, so the grammar drops FOR UPDATE there. Soft-deleted rows are
     * not returned — a missing id means the node is gone.
     *
     * @param  list<int|string|null>    $ids
     * @return Collection<string, Node> keyed by the stringified id
     */
    public function lockForUpdate(array $ids): Collection
    {
        $ids = array_values(array_unique(
            array_filter($ids, static fn (int|string|null $id): bool => $id !== null),
        ));

        if ($ids === []) {
            return new Collection;
        }

        $model = Config::nodeModel();
        $node = new $model;

        return $model::query()
            ->whereKey($ids)
            ->orderBy($node->getKeyName())
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (Node $locked): string => Identity::stringify($locked->getKey()))
            ->toBase();
    }

    /**
     * Repoint a file node's head after a new version is committed (§2.4): update
     * the head pointer plus the cached mime/size/extension in one write.
     */
    public function repointHead(
        Node $node,
        int|string $currentVersionId,
        ?string $mimeType,
        ?string $extension,
        int $sizeBytes,
    ): Node {
        $node->forceFill([
            'current_version_id' => $currentVersionId,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $sizeBytes,
        ])->save();

        return $node;
    }

    /**
     * @return Builder<Node>
     */
    private function newQuery(): Builder
    {
        $model = Config::nodeModel();

        return $model::query();
    }
}
