<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\NodeContentLifecycleRegistry;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

#[Internal]
final readonly class PurgeNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private DriveStoreRepository $storageStore,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private NodeContentLifecycleRegistry $lifecycles,
        private NodeSequence $sequence,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, PurgeNodeInput $data): void
    {
        if ($data->bypassTrashRequirement && (!$context->actor->isSystem() || $context->actor->ref()->reason !== OperationGuard::PURGE_REASON)) {
            throw new InvalidArgumentException('[Vaulter] Only the system actor "'.OperationGuard::PURGE_REASON.'" may purge a node that is not in the trash.');
        }
        $node = app(NodeLocator::class)->find(context: $context, key: $data->node, includeTrashed: true);
        $this->database->transaction(function () use ($node, $context, $data): void {
            $this->database->lockAggregate('drives', $context->driveId());
            $this->lifecycle->assertWritable($context);
            $ids = array_values($this->nodes->subtree($node)->modelKeys());
            $locked = $this->tree->lock($ids);
            $current = $this->tree->locked($locked, $node->id);
            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            $this->tree->assertNotRoot(node: $current, operation: 'delete');
            if ($current->trashed_at === null && !$data->bypassTrashRequirement) {
                throw NodeNotTrashedException::forNode($current);
            }
            foreach ($locked as $member) {
                if (!$this->tree->sameStorage($member, $context->driveId())) {
                    throw InvalidDestinationException::otherDrive($member, $context->driveId());
                }
                if ($member->kind !== NodeKind::Folder) {
                    $this->lifecycles->forKind($member->kind);
                }
            }
            // Every retained revision of every purged file leaves the quota.
            $freedBytes = app(FileContentLifecycle::class)->preparePurge($locked);
            if ($freedBytes > 0) {
                $this->storageStore->adjustUsedBytes(storage: $context->drive, delta: -$freedBytes);
            }
            foreach ($locked as $member) {
                if ($member->kind !== NodeKind::Folder) {
                    $this->lifecycles->forKind($member->kind)->purge(context: $context, node: $member);
                }
            }
            // Physical deletion in this same transaction preserves all payload FK cascades.
            $this->store->purgeSubtree(collect($ids));
            $this->recorder->record(new NodePurged(storageId: $context->driveId(), nodeIds: $ids, freedBytes: $freedBytes,
                tenantId: $context->drive->tenant_id, profile: $context->drive->profile, actor: $context->actor->ref(), correlationId: $context->correlationId));
        });
    }
}
