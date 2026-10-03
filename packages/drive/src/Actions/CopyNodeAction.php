<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\CopyNodeData;
use Vaulter\Drive\Events\NodeCopied;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\FileVersionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\FileVersionStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Repositories\Drive\StorageStoreRepository;

/**
 * Copy a node into a destination (§2.4):
 *   - folder → deep-copy the whole subtree (new node rows, same shape);
 *   - file   → new File + new FileVersion(version_no=1) whose version↔blob edge
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
        private FileVersionStoreRepository $versions,
        private StorageStoreRepository $storageStore,
        private BlobStore $blobs,
        private VaulterDatabase $database,
    ) {}

    public function execute(CopyNodeData $data): Node
    {

        $source = $data->node;
        $destParentId = $data->into?->id;
        $name = $data->name ?? $source->name;

        $exists = $this->nodes->siblingNameExists(
            storage: $source->storage,
            parentId: $destParentId,
            name: $name,
        );

        if ($exists) {
            throw NodeNameConflictException::forName(name: $name, parentId: $destParentId);
        }

        return $this->database->transaction(function () use ($source, $destParentId, $name, $data): Node {
            $addedBytes = 0;
            $copy = $this->copyNode(source: $source, parentId: $destParentId, name: $name, data: $data, addedBytes: $addedBytes);

            if ($addedBytes > 0) {
                $this->storageStore->adjustUsedBytes(storage: $source->storage, delta: $addedBytes);
            }

            event(new NodeCopied(source: $source, copy: $copy, actorId: $data->actorId));

            return $copy;
        });
    }

    /**
     * Copy one node (recursing into a folder's children). $addedBytes accumulates
     * the live logical size the copy adds, for the used_bytes adjustment.
     */
    private function copyNode(Node $source, int|string|null $parentId, string $name, CopyNodeData $data, int &$addedBytes): Node
    {
        $copy = $this->nodeStore->create([
            'storage_id' => $source->storage_id,
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
            foreach ($source->children()->whereNull('trashed_at')->get() as $child) {
                $this->copyNode(source: $child, parentId: $copy->id, name: $child->name, data: $data, addedBytes: $addedBytes);
            }
        }

        return $copy;
    }

    /**
     * Copy a file node's payload: a new File + first FileVersion that re-uses the
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

        /** @var FileVersion|null $head */
        $head = $sourceFile->currentVersion()->first();

        if (!$head instanceof FileVersion) {
            return;
        }

        $file = $this->files->create([
            'node_id' => $copy->id,
            'mime_type' => $sourceFile->mime_type,
            'size_bytes' => $sourceFile->size_bytes,
            'version_count' => 0,
        ]);

        $version = $this->versions->create([
            'file_id' => $file->id,
            'version_no' => 1,
            'blob_id' => $head->blob_id,
            'content_sha256' => $head->content_sha256,
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
        );

        $this->nodeStore->update($copy, [
            'file_id' => $file->id,
            'current_version_id' => $version->id,
        ]);
    }

    private function reattach(FileVersion $version, Blob $blob): void
    {
        /** @var FileVersionBlob $pivot */
        $pivot = $blob->getRelationValue('pivot');

        $this->blobs->attach($version, $blob, [
            'role' => $pivot->role,
            'ordinal' => $pivot->ordinal,
            'offset_bytes' => $pivot->offset_bytes,
            'length_bytes' => $pivot->length_bytes,
        ]);
    }
}
