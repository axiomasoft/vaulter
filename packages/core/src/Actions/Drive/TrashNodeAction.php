<?php

declare(strict_types=1);

namespace Vaulter\Actions\Drive;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\NodeTrashed;
use Vaulter\Models\Node;
use Vaulter\Repositories\NodeReadRepository;
use Vaulter\Repositories\NodeStoreRepository;

/**
 * Soft-trash a node and its whole subtree atomically (§2.10 / D17). The trashed
 * triplet (trashed_at, trashed_by, and trashed_from_parent_id on the root) is
 * stamped across the bounded subtree in one bulk UPDATE; the sibling-unique index
 * excludes trashed rows so the name frees up for a new sibling.
 *
 * used_bytes is NOT decremented — trashed bytes still occupy storage until
 * forceDelete (§1.5: quota counts what is physically retained, and a restore must
 * bring the bytes back). The decrement happens only in ForceDeleteNodeAction.
 *
 * M2 authorize() seam: authorize 'trash' on the node here.
 */
final readonly class TrashNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
    ) {}

    public function execute(Node $node, int|string|null $actorId = null): Node
    {
        // M2 seam: authorize 'trash' on $node here.

        if ($node->trashed_at !== null) {
            return $node;
        }

        $ids = $this->nodes->subtree($node)->modelKeys();

        return DB::transaction(function () use ($node, $ids, $actorId): Node {
            $this->store->trashSubtree(
                ids: collect($ids),
                root: $node,
                actorId: $actorId,
                at: now(),
            );

            $fresh = $node->refresh();

            event(new NodeTrashed(node: $fresh, actorId: $actorId));

            return $fresh;
        });
    }
}
