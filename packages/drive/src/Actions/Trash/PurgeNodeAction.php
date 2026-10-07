<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Trash;

use Vaulter\Content\NodeContentLifecycleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Events\Nodes\NodePurged;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNotTrashedException;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\NodeSequence;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

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
    ) {}

    public function execute(OperationContext $context, PurgeNodeInput $data): void
    {
        $node = app(NodeLocator::class)->find(context: $context, key: $data->node, includeTrashed: true);
        $this->database->transaction(function () use ($node, $context, $data): void {
            $this->database->lockTree($context->driveId());
            $ids = array_values($this->nodes->subtree($node)->modelKeys());
            $locked = $this->tree->lock($ids);
            $current = $this->tree->locked($locked, $node->id);
            $this->sequence->assertExpected(locked: $current, expected: $data->expectedSequence);
            $this->tree->assertNotRoot(node: $current, operation: 'delete');
            if ($current->trashed_at === null) {
                throw NodeNotTrashedException::forNode($current);
            }
            $freedBytes = 0;
            foreach ($locked as $member) {
                if (!$this->tree->sameStorage($member, $context->driveId())) {
                    throw InvalidDestinationException::otherDrive($member, $context->driveId());
                }
                if ($member->kind !== NodeKind::Folder) {
                    $this->lifecycles->forKind($member->kind);
                }
                if ($member->kind === NodeKind::File) {
                    $freedBytes += $member->size_bytes;
                }
            }
            app(FileContentLifecycle::class)->preparePurge($locked);
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
            event(new NodePurged(storageId: $context->driveId(), nodeIds: $ids, freedBytes: $freedBytes,
                actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));
        });
    }
}
