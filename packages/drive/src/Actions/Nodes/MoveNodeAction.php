<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Nodes\NodeMoved;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;
use Vaulter\Support\Identity;

/**
 * Re-parent a node within its storage tree — the single parent_id
 * UPDATE. Every guard runs inside the transaction, after the storage tree lock and
 * the row locks on the node and its destination:
 *   1. the root never moves;
 *   2. the destination is a live folder of the same storage (no destination = root);
 *   3. cycle — the destination must not be the node itself or one of its own
 *      descendants (bounded subtree, then parent pointers — the subtree CTE drops
 *      a descendant whose id is a suffix of an ancestor id in the path);
 *   4. the drive policy's children and depth limits at the destination;
 *   5. sibling-name conflict at the destination.
 *
 * The tree lock is what makes the cycle check sound: row locks on the node and the
 * destination alone do not cover the ancestor chain, so two moves over disjoint rows
 * (A into B's child, B into A's child) could both pass and close a loop.
 *
 * Operations authorize before this command; locked invariants remain in the Action.
 */
#[Internal]
final readonly class MoveNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DrivePolicyEnforcer $policy,
        private NameConflictResolver $names,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
        private NodeSequence $sequence,
        private NodeSnapshotMapper $nodeSnapshots,
    ) {}

    public function execute(DriveContext $context, MoveNodeInput $data): Node
    {
        $node = app(NodeLocator::class)->find(context: $context, key: $data->node);
        $intoId = $this->tree->destinationId(storage: $node->storage, into: $data->into);

        return $this->database->transaction(function () use ($node, $intoId, $data, $context): Node {
            $this->database->lockAggregate('drives', $node->drive_id);
            $this->lifecycle->assertWritable($context);

            $locked = $this->tree->lock([$node->id, $intoId]);
            $current = $this->tree->sync($node, $this->tree->locked($locked, $node->id));
            $into = $this->tree->locked($locked, $intoId);

            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            // CC3: the destination's own precondition, checked under the same tree lock.
            $this->sequence->assertExpected(locked: $into, expected: $data->expectedDestinationSequence);
            if ($current->trashed_at !== null) {
                throw InvalidDestinationException::trashed($current);
            }

            $this->tree->assertNotRoot(node: $current, operation: 'move');
            if (!$this->tree->sameStorage($into, $current->drive_id)) {
                throw CrossDriveOperationException::between(fromStorageId: $current->drive_id, toStorageId: $into->drive_id);
            }

            $this->tree->assertActiveFolder($into);
            $this->assertNoCycle(node: $current, into: $into);

            $fromParentId = $current->parent_id;
            $toParentId = $into->id;

            if (Identity::stringify($fromParentId) === Identity::stringify($toParentId)) {
                return $current;
            }

            $this->policy->assertChildren(drive: $context->drive, parentId: $into->id);
            $height = $this->nodes->height($current, $this->policy->heightBound($context->drive));
            $this->policy->assertDepth(drive: $context->drive, parent: $into, height: $height,
                currentDeepest: $this->nodes->depth($current) + $height);

            $name = $this->names->resolve($context->drive, $into->id, $current->name, $data->onConflict, $current->id,
                $this->policy->policy($context->drive)->names());
            if ($name !== $current->name) {
                $this->store->rename($current, $name);
            }

            $moved = $this->store->move(node: $current, parentId: $toParentId);

            $this->sequence->bump($moved);

            $this->recorder->record(new NodeMoved(nodeSnapshots: $this->nodeSnapshots,
                node: $moved,
                fromParentId: $fromParentId,
                toParentId: $toParentId,
                actor: $context->actor->ref(), correlationId: $context->correlationId,
            ));
            $this->recorder->record(EffectivePermissionsChanged::forNode(node: $moved, reason: DriveEventType::NodeMoved->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $moved;
        });
    }

    /**
     * The cycle guard: a node cannot move into itself nor any node in its
     * own subtree. We materialise the bounded subtree once and test membership —
     * never a recursive correlated aggregate in the request path.
     */
    private function assertNoCycle(Node $node, Node $into): void
    {
        if (Identity::stringify($into->id) === Identity::stringify($node->id)) {
            throw NodeCycleException::forMove(nodeId: $node->id, intoId: $into->id);
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

        if (in_array(Identity::stringify($into->id), $descendantIds, strict: true)
            || $this->destinationIsUnder(node: $node, into: $into)) {
            throw NodeCycleException::forMove(nodeId: $node->id, intoId: $into->id);
        }
    }

    /**
     * Walk parent_id up to the root. The adjacency CTE's path cycle guard is a
     * substring test, so an id such as 100 is "already visited" inside 2100 and
     * never appears in the subtree. Membership in that set alone would let the
     * move close a cycle. A repeated ancestor that is not this node is an
     * existing loop; stop there instead of spinning. Past the tree bound the
     * destination is treated as outside, matching a subtree that stayed complete.
     */
    private function destinationIsUnder(Node $node, Node $into): bool
    {
        $nodeId = Identity::stringify($node->id);
        $driveId = Identity::stringify($node->drive_id);
        $seen = [];
        $cursor = $into;
        $bound = $this->nodes->treeBound();

        for ($step = 0; $step <= $bound; $step++) {
            $cursorId = Identity::stringify($cursor->id);
            if ($cursorId === $nodeId) {
                return true;
            }
            if (isset($seen[$cursorId]) || $cursor->parent_id === null) {
                return false;
            }
            $seen[$cursorId] = true;
            $parent = $this->nodes->find($cursor->parent_id);
            if (!$parent instanceof Node || Identity::stringify($parent->drive_id) !== $driveId) {
                return false;
            }
            $cursor = $parent;
        }

        return false;
    }
}
