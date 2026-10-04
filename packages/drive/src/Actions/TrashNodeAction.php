<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\NodeTrashed;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

/**
 * Soft-trash a node and its whole subtree atomically (§2.10 / D17) under the storage
 * tree lock. The root is never trashed and a node already in the trash is rejected. The trashed
 * triplet (trashed_at, trashed_by, and trashed_from_parent_id on the root) is
 * stamped across the bounded subtree in one bulk UPDATE; the sibling-unique index
 * excludes trashed rows so the name frees up for a new sibling.
 *
 * used_bytes is NOT decremented — trashed bytes still occupy storage until
 * forceDelete (§1.5: quota counts what is physically retained, and a restore must
 * bring the bytes back). The decrement happens only in ForceDeleteNodeAction.
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class TrashNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): Node
    {
        return $this->database->transaction(function () use ($node, $actorId): Node {
            // The subtree UPDATE locks every descendant row after the node row, in no
            // particular id order; the tree lock keeps that from crossing a concurrent
            // move or restore that holds a descendant and waits for an ancestor.
            $this->database->lockTree($node->storage_id);

            $current = $this->tree->sync($node, $this->tree->locked($this->tree->lock([$node->id]), $node->id));

            $this->tree->assertNotRoot(node: $current, operation: 'trash');

            if ($current->trashed_at !== null) {
                throw InvalidDestinationException::trashed($current);
            }

            $ids = $this->nodes->subtree($current)->modelKeys();

            $this->store->trashSubtree(
                ids: collect($ids),
                root: $current,
                actorId: $actorId,
                at: now(),
            );

            $fresh = $current->refresh();

            event(new NodeTrashed(node: $fresh, actorId: $actorId));

            return $fresh;
        });
    }
}
