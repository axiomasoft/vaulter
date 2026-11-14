<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\NodeContentLifecycleRegistry;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\NodeCopied;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Exceptions\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;
use Vaulter\Support\Identity;

#[Internal]
final readonly class CopyNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $nodeStore,
        private DriveStoreRepository $storageStore,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DrivePolicyEnforcer $policy,
        private NodeContentLifecycleRegistry $lifecycles,
        private NameConflictResolver $names,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
        private NodeSnapshotMapper $snapshots,
    ) {}

    public function execute(DriveContext $context, CopyNodeInput $data): Node
    {
        $source = app(NodeLocator::class)->find(context: $context, key: $data->node);
        // "Copy as" is a new name; without one the copy keeps the source's stored name.
        $name = $data->name === null ? null : $this->policy->name(drive: $context->drive, name: $data->name);
        $parentId = $this->tree->destinationId(storage: $context->drive, into: $data->into);

        try {
            return $this->database->transaction(function () use ($source, $parentId, $name, $data, $context): Node {
                // Exclusive, like move and trash. A shared lock on PostgreSQL lets a create
                // commit a child under the source after this subtree is read and before
                // the row locks, and the copy then omits that child.
                $this->database->lockAggregate('drives', $context->driveId());
                $this->lifecycle->assertWritable($context);
                $sourceIds = array_map(Identity::stringify(...), $this->nodes->subtree($source)->modelKeys());
                $locked = $this->tree->lock([...$sourceIds, $parentId]);
                $current = $this->tree->locked($locked, $source->id);
                $destination = $this->tree->locked($locked, $parentId);
                $this->tree->assertNotRoot(node: $current, operation: 'copy');
                if (!$this->tree->sameStorage($destination, $context->driveId())) {
                    throw CrossDriveOperationException::between(fromStorageId: $context->driveId(), toStorageId: $destination->drive_id);
                }
                $this->tree->assertActiveFolder($destination);
                $sources = $locked->filter(fn (Node $node): bool => in_array(Identity::stringify($node->id), $sourceIds, true));
                // Freeze supported membership before creating even the destination root.
                foreach ($sources as $member) {
                    if (!$this->tree->sameStorage($member, $context->driveId())) {
                        throw InvalidDestinationException::otherDrive($member, $context->driveId());
                    }
                    $this->lifecycles->assertCopySupported($member->kind);
                }
                if ($current->trashed_at !== null) {
                    throw InvalidDestinationException::trashed($current);
                }
                $included = [(string) $current->id => true];
                // Each pass adds at least one member or stops, so the loop is bounded by the
                // member count and never by a depth that could drop deep descendants.
                do {
                    $before = count($included);
                    foreach ($sources as $member) {
                        if ($member->trashed_at === null && isset($included[(string) $member->parent_id])) {
                            $included[(string) $member->id] = true;
                        }
                    }
                } while (count($included) > $before);
                $sources = $sources->filter(fn (Node $member): bool => isset($included[(string) $member->id]));
                if ($data->authorizedNodeIds !== null
                    && array_diff($sources->map(fn (Node $member): string => (string) $member->id)->all(), $data->authorizedNodeIds) !== []) {
                    // A descendant appeared after the Operations layer authorised the subtree.
                    throw PermissionDeniedException::forAbility('copy');
                }
                app(FileContentLifecycle::class)->prepareCopy($sources);
                $addedBytes = 0;
                foreach ($sources as $member) {
                    if ($member->kind === NodeKind::File && $member->trashed_at === null) {
                        $addedBytes += $member->size_bytes;
                    }
                }
                $this->assertLimits($context, $current, $destination, $sources->all());
                $resolvedName = $this->names->resolve(drive: $context->drive, parentId: $parentId,
                    name: $name ?? $current->name, onConflict: $data->onConflict,
                    policy: $this->policy->policy($context->drive)->names());
                if ($addedBytes > 0) {
                    $this->storageStore->reserveBytes(storage: $context->drive, delta: $addedBytes);
                }
                $copiedBytes = 0;
                $copy = $this->copyNode(context: $context, source: $current, parentId: $parentId,
                    name: $resolvedName, sources: $sources->all(), addedBytes: $copiedBytes);
                if ($copiedBytes !== $addedBytes) {
                    throw new \LogicException('Copy lifecycle byte accounting is inconsistent.');
                }
                $this->recorder->record(new NodeCopied(source: $current, copy: $copy, actor: $context->actor->ref(), correlationId: $context->correlationId));

                return $copy;
            }, 3);
        } catch (CoreQuotaExceededException $exception) {
            throw new QuotaExceededException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * The copy adds one child to the destination, nests the copied subtree under it,
     * and creates each copied folder with as many children as its source has live:
     * every one of them is a new excess if it breaks the drive policy.
     *
     * @param array<string, Node> $sources the live members being copied, root included
     */
    private function assertLimits(DriveContext $context, Node $root, Node $destination, array $sources): void
    {
        $this->policy->assertChildren(drive: $context->drive, parentId: $destination->id);

        $children = [];
        foreach ($sources as $member) {
            if ($member->kind === NodeKind::File) {
                // A copy creates a new file even when the original predates a
                // lowered size limit. Apply admission before reserving bytes.
                $this->policy->assertFileSize($context->drive, $member->size_bytes);
            }
            if ((string) $member->id !== (string) $root->id) {
                $children[(string) $member->parent_id][] = (string) $member->id;
            }
        }
        foreach ($children as $parentId => $ids) {
            $this->policy->assertChildCount(drive: $context->drive, folderId: $parentId, children: count($ids));
        }

        $height = 0;
        $level = [(string) $root->id];
        while ($level !== []) {
            $next = [];
            foreach ($level as $id) {
                array_push($next, ...($children[$id] ?? []));
            }
            if ($next !== []) {
                $height++;
            }
            $level = $next;
        }
        $this->policy->assertDepth(drive: $context->drive, parent: $destination, height: $height);
    }

    /** @param array<string, Node> $sources */
    private function copyNode(DriveContext $context, Node $source, int|string $parentId, string $name, array $sources, int &$addedBytes): Node
    {
        $copy = $this->nodeStore->create([
            'drive_id' => $context->driveId(),
            'tenant_id' => $source->tenant_id,
            'parent_id' => $parentId,
            'kind' => $source->kind,
            'name' => $name,
            'mime_type' => $source->mime_type,
            'extension' => $source->extension,
            'size_bytes' => $source->size_bytes,
            'inherit_grants' => $source->inherit_grants,
            'properties' => $source->properties,
            'content_modified_at' => $source->content_modified_at,
            'content_modified_by' => $source->content_modified_by,
            'created_by' => $context->actor->ref()->id,
            'sequence' => 0,
        ]);
        if ($source->kind !== NodeKind::Folder) {
            // Both rows are held in this lock transaction; the lifecycle reads their facts, its own aggregates by id.
            $addedBytes += $this->lifecycles->forKind($source->kind)->copy(context: $context, source: $this->snapshots->facts($source), copy: $this->snapshots->facts($copy))->addedBytes;
            // Capturing the new row's facts cached a missing payload before the
            // lifecycle created it. The returned snapshot must load that payload.
            $copy->unsetRelation('file');
        } else {
            foreach ($sources as $child) {
                if ((string) $child->parent_id === (string) $source->id && $child->trashed_at === null) {
                    $this->copyNode(context: $context, source: $child, parentId: $copy->id, name: $child->name, sources: $sources, addedBytes: $addedBytes);
                }
            }
        }

        return $copy;
    }
}
