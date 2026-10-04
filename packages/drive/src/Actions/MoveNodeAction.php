<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\MoveNodeData;
use Vaulter\Drive\Events\NodeMoved;
use Vaulter\Drive\Exceptions\CrossStorageMoveException;
use Vaulter\Drive\Exceptions\CycleException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\TreeGuard;

/**
 * Re-parent a node within its storage tree — the §2.2 / H.4 single parent_id
 * UPDATE. Every guard runs inside the transaction, after the storage tree lock and
 * the row locks on the node and its destination:
 *   1. the root never moves;
 *   2. the destination is a live folder of the same storage (no destination = root);
 *   3. cycle — the destination must not be the node itself or one of its own
 *      descendants (checked via the bounded subtree, NOT whereHas('descendants'));
 *   4. sibling-name conflict at the destination.
 *
 * The tree lock is what makes the cycle check sound: row locks on the node and the
 * destination alone do not cover the ancestor chain, so two moves over disjoint rows
 * (A into B's child, B into A's child) could both pass and close a loop.
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class MoveNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(MoveNodeData $data): Node
    {
        $node = $data->node;
        $intoId = $this->tree->destinationId(storage: $node->storage, into: $data->into);

        return $this->database->transaction(function () use ($node, $intoId, $data): Node {
            $this->database->lockTree($node->drive_id);

            $locked = $this->tree->lock([$node->id, $intoId]);
            $current = $this->tree->sync($node, $this->tree->locked($locked, $node->id));
            $into = $this->tree->locked($locked, $intoId);

            $this->tree->assertNotRoot(node: $current, operation: 'move');
            if (!$this->tree->sameStorage($into, $current->drive_id)) {
                throw CrossStorageMoveException::between(fromStorageId: $current->drive_id, toStorageId: $into->drive_id);
            }

            $this->tree->assertActiveFolder($into);
            $this->assertNoCycle(node: $current, into: $into);

            $fromParentId = $current->parent_id;
            $toParentId = $into->id;

            if (Identity::stringify($fromParentId) === Identity::stringify($toParentId)) {
                return $current;
            }

            $this->assertNameAvailable(node: $current, into: $into);

            $moved = $this->store->move(node: $current, parentId: $toParentId);

            event(new NodeMoved(
                node: $moved,
                fromParentId: $fromParentId,
                toParentId: $toParentId,
                actorId: $data->actorId,
            ));

            return $moved;
        });
    }

    /**
     * The cycle guard (H.4): a node cannot move into itself nor any node in its
     * own subtree. We materialise the bounded subtree once and test membership —
     * never a recursive correlated aggregate in the request path.
     */
    private function assertNoCycle(Node $node, Node $into): void
    {
        if (Identity::stringify($into->id) === Identity::stringify($node->id)) {
            throw CycleException::forMove(nodeId: $node->id, intoId: $into->id);
        }

        $descendantIds = array_map(
            Identity::stringify(...),
            $this->nodes->subtree($node)->modelKeys(),
        );

        $this->metrics->histogram(
            name: 'vaulter_subtree_nodes_touched',
            value: count($descendantIds),
            labels: ['operation' => 'subtree_mutate'],
        );

        if (in_array(Identity::stringify($into->id), $descendantIds, strict: true)) {
            throw CycleException::forMove(nodeId: $node->id, intoId: $into->id);
        }
    }

    private function assertNameAvailable(Node $node, Node $into): void
    {
        $exists = $this->nodes->siblingNameExists(
            storage: $node->storage,
            parentId: $into->id,
            name: $node->name,
            ignoreId: $node->id,
        );

        if ($exists) {
            throw NodeNameConflictException::forName(name: $node->name, parentId: $into->id);
        }
    }
}
