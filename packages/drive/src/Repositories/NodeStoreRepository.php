<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Node;
use Vaulter\Support\Identity;

/**
 * Write-side row work for v_nodes. Bare mutations only: create/update,
 * the single-UPDATE move, and the bulk subtree state changes (trash, restore,
 * permanent soft-delete). It opens NO transactions and dispatches NO events — the
 * Drive Actions wrap every call in VaulterDatabase::transaction and emit the domain event. The
 * subtree helpers take an already-materialised id list (the caller resolved it
 * via NodeReadRepository::subtree with withMaxDepth) and issue ONE bulk UPDATE.
 */
#[Internal]
final class NodeStoreRepository
{
    public function __construct(
        private readonly DriveConfig $vaulterConfig,
        private readonly VaulterDatabase $database,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Node
    {
        // forceFill: $attributes here is ALWAYS a server-built
        // literal array from a Drive Action (never raw request input reaching this
        // repository) — mass-assignment guarding belongs at the HTTP boundary
        // (Node::$guarded), not on this trusted internal seam.
        $model = $this->vaulterConfig->model('node');
        $node = new $model;
        $node->forceFill($attributes);
        $node->save();

        // Refresh so DB-default columns (size_bytes, inherit_grants, sequence,
        // panel) are populated in-memory — callers (copy, list, the facade return)
        // read a fully hydrated row, never a half-set new model.
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
     * The move: a single parent_id UPDATE on the moved node only — its
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
     * Soft-trash a whole subtree atomically (triplet). Every node in the id
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
                'trash_root_id' => $root->getKey(),
            ]);

        // The root remembers where it hung so restore can detect a vanished
        // parent and re-home to root deterministically.
        $this->newQuery()
            ->whereKey($root->getKey())
            ->update(['trashed_from_parent_id' => $root->parent_id]);
    }

    /**
     * Clear the trashed triplet across a subtree (restore) in ONE bulk
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
                'trash_root_id' => null,
            ]);
    }

    /**
     * Physically purge a subtree's rows (stage 3) — a genuine
     * DELETE (nodes have no tombstone), so FK `cascadeOnDelete` on every dependent
     * table (files → file_revisions → file_revision_blobs, documents →
     * document_revisions, node_grants, node_shares, …) physically removes their
     * rows too — purge never leaves inline document content in the database.
     *
     * @param Collection<int, int|string> $ids
     */
    public function purgeSubtree(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $model = $this->vaulterConfig->model('node');

        if (!in_array($this->database->driver(), ['mysql', 'mariadb'], true)) {
            $model::query()->whereKey($ids->all())->delete();

            return;
        }

        // MySQL refuses a foreign-key cascade deeper than 15 levels (error 3008) and a node tree can be 130 deep: delete the
        // subtree leaves first, level by level, so that no single DELETE cascades through more than the dependents of one level.
        $parents = [];
        foreach ($ids->chunk(1000) as $chunk) {
            foreach ($model::query()->whereKey($chunk->all())->toBase()->get(['id', 'parent_id']) as $row) {
                $parents[Identity::stringify($row->id)] = $row->parent_id === null ? null : Identity::stringify($row->parent_id);
            }
        }
        $levels = [];
        foreach (array_keys($parents) as $id) {
            $depth = 0;
            $at = (string) $id;
            while (($up = $parents[$at] ?? null) !== null && array_key_exists($up, $parents)) {
                $depth++;
                $at = $up;
            }
            $levels[$depth][] = $id;
        }
        krsort($levels);
        foreach ($levels as $level) {
            foreach (array_chunk($level, 1000) as $chunk) {
                $model::query()->whereKey($chunk)->delete();
            }
        }
    }

    /**
     * Re-verify + row-lock the purge root at delete time (review fix,
     * TOCTOU). `PurgeTrashCommand` materialises purge candidates before opening
     * a transaction; between that read and the transaction actually running, a
     * concurrent `POST nodes/{id}/restore` can clear `trashed_at` on the same
     * node (queue delay, a slow prior candidate in the same batch, or plain bad
     * timing). Because purge is now irreversible (docs/adr/0016-trash-retention-erasure.md /), acting
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

        $model = $this->vaulterConfig->model('node');

        /** @var Node|null $node */
        $node = $model::query()
            ->whereKey($id)
            ->whereNotNull('trashed_at')
            ->whereColumn('trash_root_id', 'id')
            ->where('trashed_at', '<=', $threshold)
            ->lockForUpdate()
            ->first();

        return $node;
    }

    /**
     * Lock live node rows for the rest of the transaction, in ascending id order so
     * two operations over overlapping nodes queue instead of deadlocking (level 2:
     * after lockAggregate, before aggregate and quota locks). SQLite has no row locks and
     * serialises writers, so the grammar drops FOR UPDATE there. A missing id means
     * the node is gone.
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

        $model = $this->vaulterConfig->model('node');
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
     * Stamp a content change (who and when) without touching the
     * sequence — document content writes change the node's content, not its
     * editorial state.
     */
    public function touch(Node $node, int|string|null $actorId): Node
    {
        $now = $node->freshTimestamp();
        $node->forceFill([
            'updated_by' => $actorId,
            'updated_at' => $now,
            'content_modified_by' => $actorId,
            'content_modified_at' => $now,
        ])->save();

        return $node;
    }

    /**
     * Refresh a file node's cached mime/size/extension after its aggregate's head
     * moved and stamp the content change. The head pointer itself lives on
     * v_files.
     */
    public function cacheHead(
        Node $node,
        ?string $mimeType,
        ?string $extension,
        int $sizeBytes,
        int|string|null $actorId = null,
    ): Node {
        $node->forceFill([
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $sizeBytes,
            'updated_by' => $actorId,
            'content_modified_by' => $actorId,
            'content_modified_at' => $node->freshTimestamp(),
        ])->save();

        return $node;
    }

    /**
     * @return Builder<Node>
     */
    private function newQuery(): Builder
    {
        $model = $this->vaulterConfig->model('node');

        return $model::query();
    }
}
