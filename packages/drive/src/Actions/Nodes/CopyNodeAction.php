<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Content\NodeContentLifecycleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Events\Nodes\NodeCopied;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

final readonly class CopyNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $nodeStore,
        private DriveStoreRepository $storageStore,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveConfig $driveConfig,
        private NodeContentLifecycleRegistry $lifecycles,
        private NameConflictResolver $names,
    ) {}

    public function execute(OperationContext $context, CopyNodeInput $data): Node
    {
        $source = app(NodeLocator::class)->find(context: $context, key: $data->node);
        $name = $data->name === null ? null : NodeName::normalize(name: $data->name, maxLength: $this->driveConfig->nameMaxLength());
        $parentId = $this->tree->destinationId(storage: $context->drive, into: $data->into);

        try {
            return $this->database->transaction(function () use ($source, $parentId, $name, $data, $context): Node {
                $this->database->lockTreeShared($context->driveId());
                $sourceIds = array_values($this->nodes->subtree($source)->modelKeys());
                $locked = $this->tree->lock([...$sourceIds, $parentId]);
                $current = $this->tree->locked($locked, $source->id);
                $destination = $this->tree->locked($locked, $parentId);
                $this->tree->assertNotRoot(node: $current, operation: 'copy');
                if (!$this->tree->sameStorage($destination, $context->driveId())) {
                    throw CrossDriveOperationException::between(fromStorageId: $context->driveId(), toStorageId: $destination->drive_id);
                }
                $this->tree->assertActiveFolder($destination);
                $sources = $locked->filter(fn (Node $node): bool => in_array($node->id, $sourceIds, true));
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
                for ($depth = 0; $depth < NodeReadRepository::DEFAULT_MAX_DEPTH; $depth++) {
                    $before = count($included);
                    foreach ($sources as $member) {
                        if ($member->trashed_at === null && isset($included[(string) $member->parent_id])) {
                            $included[(string) $member->id] = true;
                        }
                    }
                    if (count($included) === $before) {
                        break;
                    }
                }
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
                $resolvedName = $this->names->resolve(drive: $context->drive, parentId: $parentId,
                    name: $name ?? $current->name, onConflict: $data->onConflict);
                if ($addedBytes > 0) {
                    $this->storageStore->reserveBytes(storage: $context->drive, delta: $addedBytes);
                }
                $copiedBytes = 0;
                $copy = $this->copyNode(context: $context, source: $current, parentId: $parentId,
                    name: $resolvedName, sources: $sources->all(), addedBytes: $copiedBytes);
                if ($copiedBytes !== $addedBytes) {
                    throw new \LogicException('Copy lifecycle byte accounting is inconsistent.');
                }
                event(new NodeCopied(source: $current, copy: $copy, actorId: $context->actor->ref()->id,
                    actor: $context->actor->ref(), correlationId: $context->correlationId));

                return $copy;
            }, 3);
        } catch (CoreQuotaExceededException $exception) {
            throw new QuotaExceededException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /** @param array<string, Node> $sources */
    private function copyNode(OperationContext $context, Node $source, int|string $parentId, string $name, array $sources, int &$addedBytes): Node
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
            'is_pinned' => $source->is_pinned,
            'inherit_grants' => $source->inherit_grants,
            'position' => $source->position,
            'owner_id' => $context->actor->ref()->id,
            'created_by' => $context->actor->ref()->id,
            'sequence' => 0,
        ]);
        if ($source->kind !== NodeKind::Folder) {
            $addedBytes += $this->lifecycles->forKind($source->kind)->copy(context: $context, source: $source, copy: $copy)->addedBytes;
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
