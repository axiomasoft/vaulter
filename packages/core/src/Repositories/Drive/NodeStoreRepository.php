<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_nodes (§2.2). Bare mutations only: create/update,
 * the single-UPDATE move, and the bulk subtree state changes (trash, restore,
 * permanent soft-delete). It opens NO transactions and dispatches NO events — the
 * Drive Actions wrap every call in DB::transaction and emit the domain event. The
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
        $model = Config::nodeModel();
        $node = new $model;
        $node->fill($attributes);
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
