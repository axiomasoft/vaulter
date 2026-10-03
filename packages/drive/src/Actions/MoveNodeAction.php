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

/**
 * Re-parent a node within its storage tree — the §2.2 / H.4 single parent_id
 * UPDATE. Three guards run BEFORE the write:
 *   1. cross-storage — a move never crosses storage_id (the tree boundary);
 *   2. cycle — the destination must not be the node itself or one of its own
 *      descendants (checked via the bounded subtree, NOT whereHas('descendants'));
 *   3. sibling-name conflict at the destination.
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
    ) {}

    public function execute(MoveNodeData $data): Node
    {

        $node = $data->node;
        $into = $data->into;

        $this->assertSameStorage(node: $node, into: $into);
        $this->assertNoCycle(node: $node, into: $into);
        $this->assertNameAvailable(node: $node, into: $into);

        $fromParentId = $node->parent_id;
        $toParentId = $into?->id;

        if ($fromParentId === $toParentId) {
            return $node;
        }

        return $this->database->transaction(function () use ($node, $fromParentId, $toParentId, $data): Node {
            $moved = $this->store->move(node: $node, parentId: $toParentId);

            event(new NodeMoved(
                node: $moved,
                fromParentId: $fromParentId,
                toParentId: $toParentId,
                actorId: $data->actorId,
            ));

            return $moved;
        });
    }

    private function assertSameStorage(Node $node, ?Node $into): void
    {
        if ($into === null) {
            return;
        }

        if ($node->storage_id !== $into->storage_id) {
            throw CrossStorageMoveException::between(
                fromStorageId: $node->storage_id,
                toStorageId: $into->storage_id,
            );
        }
    }

    /**
     * The cycle guard (H.4): a node cannot move into itself nor any node in its
     * own subtree. We materialise the bounded subtree once and test membership —
     * never a recursive correlated aggregate in the request path.
     */
    private function assertNoCycle(Node $node, ?Node $into): void
    {
        if ($into === null) {
            return;
        }

        if ($into->id === $node->id) {
            throw CycleException::forMove(nodeId: $node->id, intoId: $into->id);
        }

        $descendantIds = $this->nodes->subtree($node)->modelKeys();

        $this->metrics->histogram(
            name: 'vaulter_subtree_nodes_touched',
            value: count($descendantIds),
            labels: ['operation' => 'subtree_mutate'],
        );

        if (in_array($into->id, $descendantIds, strict: false)) {
            throw CycleException::forMove(nodeId: $node->id, intoId: $into->id);
        }
    }

    private function assertNameAvailable(Node $node, ?Node $into): void
    {
        $parentId = $into?->id;

        $exists = $this->nodes->siblingNameExists(
            storage: $node->storage,
            parentId: $parentId,
            name: $node->name,
            ignoreId: $node->id,
        );

        if ($exists) {
            throw NodeNameConflictException::forName(name: $node->name, parentId: $parentId);
        }
    }
}
