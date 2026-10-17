<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Drive\Exceptions\Nodes\NodeTrashedByAncestorException;
use Vaulter\Drive\Exceptions\Nodes\RestoreDestinationChangedException;
use Vaulter\Drive\Exceptions\Nodes\RestoreDestinationRequiredException;
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

#[Internal]
final readonly class RestoreNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private NodeLocator $locator,
        private NodeSequence $sequence,
        private NameConflictResolver $names,
        private DrivePolicyEnforcer $policy,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    /**
     * Public authorization must use the destination this resolution actually chooses
     * the explicit one, else the original parent. A restore never moves a
     * node silently to the root.
     */
    public function resolveDestination(DriveContext $context, Node $node, Node|NodeSnapshot|int|string|null $explicit = null): Node
    {
        $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
        if ($node->trashed_at === null) {
            throw NodeNotTrashedException::forNode($node);
        }
        $this->assertTrashRoot($node);
        if ($explicit !== null) {
            $destination = $this->locator->find(context: $context, key: $explicit);
            $this->tree->assertActiveFolder($destination);

            return $destination;
        }
        $originalId = $node->trashed_from_parent_id;
        if ($originalId === null) {
            throw RestoreDestinationRequiredException::forNode($node);
        }
        try {
            $original = $this->locator->find(context: $context, key: $originalId);
            $this->tree->assertActiveFolder($original);
        } catch (NodeNotFoundException|InvalidDestinationException) {
            throw RestoreDestinationRequiredException::forNode($node);
        }

        return $original;
    }

    public function execute(DriveContext $context, RestoreNodeInput $data): Node
    {
        $node = $this->locator->find(context: $context, key: $data->node, includeTrashed: true);

        return $this->database->transaction(function () use ($node, $data, $context): Node {
            $this->database->lockAggregate('drives', $context->driveId());
            $this->lifecycle->assertWritable($context);
            $fresh = $this->locator->find(context: $context, key: $node, includeTrashed: true);
            $ids = $this->nodes->subtree($fresh)->modelKeys();
            $locked = $this->tree->lock([...$ids, $data->destination->id]);
            $current = $this->tree->locked($locked, $node->id);
            // On the locked row and before anything moves, so a stale caller changes nothing.
            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            $this->tree->assertNotRoot(node: $current, operation: 'restore');
            if ($current->trashed_at === null) {
                throw NodeNotTrashedException::forNode($current);
            }
            $this->assertTrashRoot($current);
            // The destination was authorized before the locks; it must still be a live folder.
            $destination = $locked->get(Identity::stringify($data->destination->id));
            if (!$destination instanceof Node || !$this->tree->sameStorage($destination, $current->drive_id)) {
                throw new RestoreDestinationChangedException;
            }
            try {
                $this->tree->assertActiveFolder($destination);
            } catch (InvalidDestinationException) {
                throw new RestoreDestinationChangedException;
            }
            // A destination inside the subtree being restored would detach it.
            if (in_array($destination->id, $ids, true)) {
                throw InvalidDestinationException::trashed($destination);
            }
            // Only the members trashed together with this root come back; descendants
            // trashed earlier on their own stay in the trash under their own roots.
            $changed = $locked->filter(fn (Node $member): bool => in_array($member->id, $ids, true)
                && $member->trashed_at !== null
                && $member->trash_root_id !== null
                && Identity::stringify($member->trash_root_id) === Identity::stringify($current->id));
            foreach ($changed as $member) {
                if (!$this->tree->sameStorage($member, $context->driveId())) {
                    throw InvalidDestinationException::otherDrive($member, $context->driveId());
                }
            }
            // Back under its own parent the node was never gone from the children
            // count; anywhere else it is one more child, possibly deeper.
            if (Identity::stringify($destination->id) !== Identity::stringify($current->parent_id)) {
                $this->policy->assertChildren(drive: $context->drive, parentId: $destination->id);
                $height = $this->nodes->height($current, $this->policy->heightBound($context->drive));
                $this->policy->assertDepth(drive: $context->drive, parent: $destination, height: $height,
                    currentDeepest: $this->nodes->depth($current) + $height);
            }
            $name = $this->names->resolve(drive: $context->drive, parentId: $destination->id,
                name: $current->name, onConflict: $data->onConflict, ignoreId: $current->id,
                policy: $this->policy->policy($context->drive)->names());
            $this->store->update(node: $current, attributes: ['parent_id' => $destination->id, 'name' => $name]);
            $this->store->restoreSubtree($changed->map(fn (Node $member) => $member->id)->values());
            foreach ($changed as $member) {
                $this->sequence->bump($member);
            }
            $fresh = $current->refresh();
            $this->recorder->record(new NodeRestored(node: $fresh, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $fresh;
        });
    }

    /** A member comes back with its trash root, never on its own. */
    private function assertTrashRoot(Node $node): void
    {
        if ($node->trashed_at === null || $node->trash_root_id === null) {
            return;
        }
        if (Identity::stringify($node->trash_root_id) !== Identity::stringify($node->id)) {
            throw NodeTrashedByAncestorException::forNode($node, $node->trash_root_id);
        }
    }
}
