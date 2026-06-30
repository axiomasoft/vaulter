<?php

declare(strict_types=1);

namespace Vaulter\Actions\Drive;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\Drive\NodeRestored;
use Vaulter\Exceptions\Drive\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

/**
 * Restore a trashed node and its subtree (§2.10). Clears the trashed triplet
 * across the bounded subtree in one bulk UPDATE. Two deterministic destination
 * rules (§2.10 restore-collision):
 *   - if the original parent is gone (deleted / itself trashed), re-home the node
 *     to the storage root (parent_id = null) rather than dangling it;
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
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): Node
    {

        if ($node->trashed_at === null) {
            return $node;
        }

        $destinationParentId = $this->resolveDestinationParent($node);

        if ($this->liveSiblingOwnsName(node: $node, parentId: $destinationParentId)) {
            throw NodeNameConflictException::forName(name: $node->name, parentId: $destinationParentId);
        }

        $ids = $this->nodes->subtree($node)->modelKeys();

        return DB::transaction(function () use ($node, $ids, $destinationParentId, $actorId): Node {
            // Re-home the subtree root first (so the cleared triplet lands on a
            // node already hung at its restored destination), then clear trash.
            if ($node->parent_id !== $destinationParentId) {
                $this->store->move(node: $node, parentId: $destinationParentId);
            }

            $this->store->restoreSubtree(collect($ids));

            $fresh = $node->refresh();

            event(new NodeRestored(node: $fresh, actorId: $actorId));

            return $fresh;
        });
    }

    /**
     * The destination parent for the restored node: its recorded original parent
     * when that parent is still live, else the storage root (null).
     */
    private function resolveDestinationParent(Node $node): int|string|null
    {
        $originalParentId = $node->trashed_from_parent_id ?? $node->parent_id;

        // No recorded parent (or it was the very top) → the storage root node.
        $rootId = $node->storage->root_node_id;

        if ($originalParentId === null) {
            return $rootId;
        }

        $model = Config::nodeModel();

        $parentIsLive = $model::query()
            ->whereKey($originalParentId)
            ->whereNull('trashed_at')
            ->whereNull('deleted_at')
            ->exists();

        // The original home vanished → re-home to root deterministically.
        return $parentIsLive ? $originalParentId : $rootId;
    }

    private function liveSiblingOwnsName(Node $node, int|string|null $parentId): bool
    {
        return $this->nodes->siblingNameExists(
            storage: $node->storage,
            parentId: $parentId,
            name: $node->name,
            ignoreId: $node->id,
        );
    }
}
