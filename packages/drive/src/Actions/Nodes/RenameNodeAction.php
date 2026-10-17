<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Nodes\RenameNodeInput;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
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

/**
 * Rename a node in place, subject to the sibling-name conflict check at
 * its current parent (the node itself is excluded from the check, so changing
 * only the case of a name succeeds). The root is
 * never renamed; the check runs against the locked row.
 *
 * Operations authorize before this command; locked invariants remain in the Action.
 */
#[Internal]
final readonly class RenameNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DrivePolicyEnforcer $policy,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, RenameNodeInput $data): Node
    {
        $node = app(NodeLocator::class)->find(context: $context, key: $data->node);
        // A rename is a new name: it passes the policy even when the old one would not.
        $name = $this->policy->name(drive: $context->drive, name: $data->name);

        return $this->database->transaction(function () use ($node, $name, $data, $context): Node {
            $this->database->lockTreeShared($node->drive_id);
            $this->lifecycle->assertWritable($context);
            // A move may have completed before the tree lock was acquired. Locate
            // its current parent now and lock the full set in primary-key order.
            $fresh = app(NodeLocator::class)->find(context: $context, key: $node->id);
            $locked = $this->tree->lock([$fresh->id, $fresh->parent_id]);
            $current = $this->tree->sync($node, $this->tree->locked($locked, $fresh->id));
            app(NodeSequence::class)->assertExpected(locked: $current, expected: $data->expectedSequence);
            if ($current->trashed_at !== null) {
                throw InvalidDestinationException::trashed($current);
            }

            $this->tree->assertNotRoot(node: $current, operation: 'rename');

            $fromName = $current->name;

            if ($fromName === $name) {
                return $current;
            }

            $exists = $this->nodes->siblingNameExists(
                storage: $current->storage,
                parentId: $current->parent_id,
                name: $name,
                ignoreId: $current->id,
            );

            if ($exists) {
                throw NodeNameConflictException::forName(name: $name, parentId: $current->parent_id);
            }

            $renamed = $this->store->rename(node: $current, name: $name);

            app(NodeSequence::class)->bump($renamed);

            $this->recorder->record(new NodeRenamed(
                node: $renamed,
                fromName: $fromName,
                toName: $name,
                actor: $context->actor->ref(), correlationId: $context->correlationId,
            ));

            return $renamed;
        });
    }
}
