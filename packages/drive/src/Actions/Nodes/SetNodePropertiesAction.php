<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Nodes\SetNodePropertiesInput;
use Vaulter\Drive\Events\Nodes\NodeUpdated;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

/**
 * Patch one namespace of a node's properties atomically: the set keys
 * are written, the unset keys removed, and the result checked against the drive
 * policy limits under the node lock. A change bumps the node sequence; a patch
 * that changes nothing does not. The vaulter namespaces are the system's.
 *
 * Operations authorize before this command; locked invariants remain in the Action.
 */
#[Internal]
final readonly class SetNodePropertiesAction
{
    public function __construct(
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private NodeLocator $locator,
        private NodeSequence $sequence,
        private NodeStoreRepository $store,
        private DrivePolicyEnforcer $policy,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, SetNodePropertiesInput $data): Node
    {
        $node = $this->locator->find(context: $context, key: $data->node);

        return $this->database->transaction(function () use ($context, $data, $node): Node {
            $this->database->lockTreeShared($node->drive_id);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->sync($node, $this->tree->locked($this->tree->lock([$node->id]), $node->id));
            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            if ($current->trashed_at !== null) {
                throw InvalidDestinationException::trashed($current);
            }

            $next = $this->policy->patchProperties(drive: $context->drive, current: $current->properties,
                namespace: $data->namespace, set: $data->set, unset: $data->unset, system: $context->actor->isSystem());
            if ($next == $current->properties) {
                return $current;
            }

            $this->store->update(node: $current, attributes: ['properties' => $next, 'updated_by' => $context->actor->ref()->id]);
            $this->sequence->bump($current);
            $this->recorder->record(new NodeUpdated(node: $current, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $current;
        });
    }
}
