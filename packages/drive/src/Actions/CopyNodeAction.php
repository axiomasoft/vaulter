<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\CopyNodeData;
use Vaulter\Drive\Events\NodeCopied;
use Vaulter\Drive\Exceptions\CrossStorageMoveException;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Copy a node into a destination (§2.4) — the locked destination must be a live
 * folder of the source's storage, and the root itself is never copied:
 *   - folder → deep-copy the whole subtree (new node rows, same shape);
 *   - file   → new File + new FileRevision(number=1) whose version↔blob edge
 *     references the SAME blob, so content-addressed dedup keeps ONE physical
 *     object — a copy costs a row, not bytes.
 *
 * used_bytes counts LIVE logical bytes, so a copy increments it by the copied
 * size even though the blob is shared (the quota models what the storage holds
 * logically, not the deduped physical footprint).
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class CopyNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $nodeStore,
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $versions,
        private DriveStoreRepository $storageStore,
        private BlobStore $blobs,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(CopyNodeData $data): Node
    {
        $source = $data->node;
        // An explicit name is new input; the source name was validated when it was written.
        $name = $data->name === null ? $source->name : NodeName::normalize($data->name);
        $destParentId = $this->tree->destinationId(storage: $source->storage, into: $data->into);

        $this->tree->assertNotRoot(node: $source, operation: 'copy');

        try {
            return $this->database->transaction(function () use ($source, $destParentId, $name, $data): Node {
                $this->database->lockTreeShared($source->drive_id);

                // Freeze the source membership before inserting any copies, then lock
                // every source node with the destination in ascending id order.
                $sourceIds = array_values($this->nodes->subtree($source)->modelKeys());
                $locked = $this->tree->lock([...$sourceIds, $destParentId]);
                $current = $this->tree->locked($locked, $source->id);
                $destination = $this->tree->locked($locked, $destParentId);
                $resolvedName = $data->name === null ? $current->name : $name;
                if (!$this->tree->sameStorage($destination, $source->drive_id)) {
                    throw CrossStorageMoveException::between(fromStorageId: $source->drive_id, toStorageId: $destination->drive_id);
                }

                $this->tree->assertActiveFolder($destination);

                $exists = $this->nodes->siblingNameExists(
                    storage: $source->storage,
                    parentId: $destParentId,
                    name: $resolvedName,
                );

                if ($exists) {
                    throw NodeNameConflictException::forName(name: $resolvedName, parentId: $destParentId);
                }

                $addedBytes = 0;
                $copy = $this->copyNode(source: $current, parentId: $destParentId, name: $resolvedName, data: $data, addedBytes: $addedBytes, sourceIds: $sourceIds);

                if ($addedBytes > 0) {
                    $this->storageStore->reserveBytes(storage: $source->storage, delta: $addedBytes);
                }

                event(new NodeCopied(source: $current, copy: $copy, actorId: $data->actorId));

                return $copy;
            }, 3);
        } catch (CoreQuotaExceededException $e) {
            throw new QuotaExceededException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Copy one node (recursing into a folder's children). $addedBytes accumulates
     * the live logical size the copy adds, for the used_bytes adjustment.
     *
     * @param list<int|string> $sourceIds
     */
    private function copyNode(Node $source, int|string $parentId, string $name, CopyNodeData $data, int &$addedBytes, array $sourceIds): Node
    {
        $copy = $this->nodeStore->create([
            'drive_id' => $source->drive_id,
            'parent_id' => $parentId,
            'kind' => $source->kind,
            'name' => $name,
            'mime_type' => $source->mime_type,
            'extension' => $source->extension,
            'size_bytes' => $source->size_bytes ?? 0,
            'is_pinned' => $source->is_pinned ?? false,
            'inherit_grants' => $source->inherit_grants ?? true,
            'shortcut_target_id' => $source->shortcut_target_id,
            'owner_id' => $data->actorId,
            'created_by' => $data->actorId,
        ]);

        if ($source->kind === NodeKind::File) {
            $this->copyFilePayload(source: $source, copy: $copy, data: $data);
            $addedBytes += $source->size_bytes ?? 0;
        }

        if ($source->kind === NodeKind::Folder) {
            foreach ($source->children()->whereIn('id', $sourceIds)->whereNull('trashed_at')->get() as $child) {
                $this->copyNode(source: $child, parentId: $copy->id, name: $child->name, data: $data, addedBytes: $addedBytes, sourceIds: $sourceIds);
            }
        }

        return $copy;
    }

    /**
     * Copy a file node's payload: a new File + first FileRevision that re-uses the
     * source head's blob(s) through NEW edges (§2.4 dedup). No bytes are staged or
     * committed — the blob already exists, we just add another reference.
     */
    private function copyFilePayload(Node $source, Node $copy, CopyNodeData $data): void
    {
        /** @var File|null $sourceFile */
        $sourceFile = $source->file()->first();

        if (!$sourceFile instanceof File) {
            return;
        }

        /** @var FileRevision|null $head */
        $head = $sourceFile->currentVersion()->first();

        if (!$head instanceof FileRevision) {
            return;
        }

        $file = $this->files->create([
            'node_id' => $copy->id,
            'mime_type' => $sourceFile->mime_type,
            'size_bytes' => $sourceFile->size_bytes,
            'revision_count' => 0,
        ]);

        $version = $this->versions->create([
            'file_id' => $file->id,
            'number' => 1,
            'blob_id' => $head->blob_id,
            'sha256' => $head->sha256,
            'size_bytes' => $head->size_bytes,
            'mime_type' => $head->mime_type,
            'original_name' => $head->original_name,
            'status' => 'committed',
            'created_by' => $data->actorId,
            'committed_at' => now(),
        ]);

        // Re-reference every blob the source head pointed at (data/manifest/chunk)
        // through fresh edges — dedup keeps the one physical object alive.
        foreach ($head->blobs as $blob) {
            $this->reattach(version: $version, blob: $blob);
        }

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $sourceFile->mime_type,
            sizeBytes: $sourceFile->size_bytes,
            versionNo: 1,
        );

        $this->nodeStore->update($copy, [
            'file_id' => $file->id,
            'current_version_id' => $version->id,
        ]);
    }

    private function reattach(FileRevision $version, Blob $blob): void
    {
        /** @var FileRevisionBlob $pivot */
        $pivot = $blob->getRelationValue('pivot');

        $this->blobs->attach($version, $blob, [
            'role' => $pivot->role,
            'ordinal' => $pivot->ordinal,
            'offset_bytes' => $pivot->offset_bytes,
            'length_bytes' => $pivot->length_bytes,
        ]);
    }
}
