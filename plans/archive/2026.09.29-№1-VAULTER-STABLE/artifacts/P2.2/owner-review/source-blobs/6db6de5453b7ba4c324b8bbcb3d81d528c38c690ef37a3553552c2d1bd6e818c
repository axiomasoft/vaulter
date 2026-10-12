<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use Illuminate\Support\Collection;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\TreeGuard;

/**
 * Restore a trashed node and its subtree (§2.10). Clears the trashed triplet
 * across the bounded subtree in one bulk UPDATE. Runs under the storage tree lock
 * (it re-parents) with the node, its original parent and the root locked, and only
 * accepts a node that is actually in the trash. Two deterministic destination
 * rules (§2.10 restore-collision):
 *   - if the original parent is gone (deleted / itself trashed / no longer a
 *     folder), re-home the node to the storage root rather than dangling it;
 *   - if a LIVE sibling now owns the node's name at the resolved destination,
 *     throw NodeNameConflictException — the caller renames then retries.
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class RestoreNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): Node
    {
        $rootId = $this->tree->destinationId(storage: $node->storage, into: null);

        return $this->database->transaction(function () use ($node, $rootId, $actorId): Node {
            $this->database->lockTree($node->drive_id);

            $current = $this->tree->sync($node, $this->tree->locked($this->tree->lock([$node->id, $rootId]), $node->id));

            if ($current->trashed_at === null) {
                throw NodeNotTrashedException::forNode($current);
            }

            // The original parent is read from the locked row, not the caller's model.
            // Locking it after the node is safe: the tree lock excludes every other
            // operation that holds more than one node row.
            $originalParentId = $current->trashed_from_parent_id ?? $current->parent_id;
            $locked = $this->tree->lock([$originalParentId]);
            $destinationParentId = $this->resolveDestinationParent(node: $current, locked: $locked, rootId: $rootId);

            if ($this->liveSiblingOwnsName(node: $current, parentId: $destinationParentId)) {
                throw NodeNameConflictException::forName(name: $current->name, parentId: $destinationParentId);
            }

            $ids = $this->nodes->subtree($current)->modelKeys();

            // Re-home the subtree root first (so the cleared triplet lands on a
            // node already hung at its restored destination), then clear trash.
            if (Identity::stringify($current->parent_id) !== Identity::stringify($destinationParentId)) {
                $this->store->move(node: $current, parentId: $destinationParentId);
            }

            $this->store->restoreSubtree(collect($ids));

            $fresh = $current->refresh();

            event(new NodeRestored(node: $fresh, actorId: $actorId));

            return $fresh;
        });
    }

    /**
     * The destination parent for the restored node: its recorded original parent
     * when that row is still a live folder of the same storage, else the root. The
     * row was locked with the node, so it cannot be trashed or moved meanwhile.
     *
     * @param Collection<string, Node> $locked
     */
    private function resolveDestinationParent(Node $node, Collection $locked, int|string $rootId): int|string
    {
        $originalParentId = $node->trashed_from_parent_id ?? $node->parent_id;

        if ($originalParentId === null) {
            return $rootId;
        }

        $parent = $locked->get(Identity::stringify($originalParentId));

        if (!$parent instanceof Node || !$this->tree->sameStorage($parent, $node->drive_id)) {
            return $rootId;
        }

        try {
            $this->tree->assertActiveFolder($parent);
        } catch (InvalidDestinationException) {
            return $rootId;
        }

        return $parent->id;
    }

    private function liveSiblingOwnsName(Node $node, int|string $parentId): bool
    {
        return $this->nodes->siblingNameExists(
            storage: $node->storage,
            parentId: $parentId,
            name: $node->name,
            ignoreId: $node->id,
        );
    }
}
