<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use Illuminate\Support\Collection;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Exceptions\Nodes\RestoreDestinationChangedException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\NodeSequence;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\TreeGuard;

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
    ) {}

    /** Public authorization must use the destination this resolution actually chooses. */
    public function resolveDestination(OperationContext $context, Node $node, Node|int|string|null $explicit = null): Node
    {
        $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
        $originalId = $node->trashed_from_parent_id ?? $node->parent_id;
        if ($originalId !== null) {
            try {
                $original = $this->locator->find(context: $context, key: $originalId);
                $this->tree->assertActiveFolder($original);

                return $original;
            } catch (NodeNotFoundException|InvalidDestinationException) {
                // A missing/inactive original parent falls through to the requested destination.
            }
        }
        $destination = $this->locator->find(context: $context,
            key: $explicit ?? $this->tree->destinationId(storage: $context->drive, into: null));
        $this->tree->assertActiveFolder($destination);

        return $destination;
    }

    public function execute(OperationContext $context, RestoreNodeInput $data): Node
    {
        $node = $this->locator->find(context: $context, key: $data->node, includeTrashed: true);
        $rootId = $this->tree->destinationId(storage: $context->drive, into: null);

        return $this->database->transaction(function () use ($node, $rootId, $data, $context): Node {
            $this->database->lockTree($context->driveId());
            $fresh = $this->locator->find(context: $context, key: $node, includeTrashed: true);
            $ids = $this->nodes->subtree($fresh)->modelKeys();
            $originalId = $fresh->trashed_from_parent_id ?? $fresh->parent_id;
            $locked = $this->tree->lock([...$ids, $originalId, $rootId, $data->destination->id]);
            $current = $this->tree->locked($locked, $node->id);
            $this->tree->assertNotRoot(node: $current, operation: 'restore');
            if ($current->trashed_at === null) {
                throw NodeNotTrashedException::forNode($current);
            }
            $destination = $this->chosenLockedDestination(current: $current, locked: $locked,
                precheckedId: $data->destination->id, rootId: $rootId);
            if (Identity::stringify($destination->id) !== Identity::stringify($data->destination->id)) {
                throw new RestoreDestinationChangedException;
            }
            $this->tree->assertActiveFolder($destination);
            // A fallback must not attach a root inside the subtree being restored.
            if (in_array($destination->id, $ids, true)) {
                throw InvalidDestinationException::trashed($destination);
            }
            $changed = $locked->filter(fn (Node $member): bool => in_array($member->id, $ids, true) && $member->trashed_at !== null);
            foreach ($changed as $member) {
                if (!$this->tree->sameStorage($member, $context->driveId())) {
                    throw InvalidDestinationException::otherDrive($member, $context->driveId());
                }
            }
            $name = $this->names->resolve(drive: $context->drive, parentId: $destination->id,
                name: $current->name, onConflict: $data->onConflict, ignoreId: $current->id);
            $this->store->update(node: $current, attributes: ['parent_id' => $destination->id, 'name' => $name]);
            $this->store->restoreSubtree($changed->map(fn (Node $member) => $member->id)->values());
            foreach ($changed as $member) {
                $this->sequence->bump($member);
            }
            $fresh = $current->refresh();
            event(new NodeRestored(node: $fresh, actorId: $context->actor->ref()->id,
                actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $fresh;
        });
    }

    /** @param Collection<string, Node> $locked */
    private function chosenLockedDestination(Node $current, Collection $locked, int|string $precheckedId, int|string $rootId): Node
    {
        $originalId = $current->trashed_from_parent_id ?? $current->parent_id;
        if ($originalId !== null) {
            $original = $locked->get(Identity::stringify($originalId));
            if ($original instanceof Node && $this->tree->sameStorage($original, $current->drive_id)) {
                try {
                    $this->tree->assertActiveFolder($original);

                    return $original;
                } catch (InvalidDestinationException) {
                }
            }
        }
        $prechecked = $locked->get(Identity::stringify($precheckedId));
        if ($prechecked instanceof Node && $this->tree->sameStorage($prechecked, $current->drive_id)) {
            try {
                $this->tree->assertActiveFolder($prechecked);

                return $prechecked;
            } catch (InvalidDestinationException) {
            }
        }

        return $this->tree->locked($locked, $rootId);
    }
}
