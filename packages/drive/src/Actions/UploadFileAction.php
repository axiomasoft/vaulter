<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use LogicException;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\UploadFileData;
use Vaulter\Drive\Events\FileUploaded;
use Vaulter\Drive\Events\NodeVersionCommitted;
use Vaulter\Drive\Exceptions\CrossStorageMoveException;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\FileVersionStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Repositories\Drive\StorageStoreRepository;
use Vaulter\Support\LoadTier;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Commit an upload into the Drive (§2.4 commit flow): stage → BlobStore.commit
 * (content-addressed dedup) → File + FileVersion(version_no) + version↔blob edge
 * → file node (current_version_id, mime, size, extension).
 *
 * Quota (§1.5 eager): the incoming size is checked against the storage's
 * quota_bytes BEFORE the blob is committed (we never store bytes we must reject),
 * and used_bytes is incremented WITHIN the transaction.
 *
 * Two paths share one Action:
 *   - new file → create Node(kind=file) + File + FileVersion(version_no=1);
 *   - new version (upload onto an existing file node) → bump version_no, repoint
 *     current_version_id, mark the prior head superseded, and adjust used_bytes by
 *     the SIZE DELTA (new size − previous head size), not the full size.
 *
 * Authorization (M2a / H.2) is enforced upstream at the HTTP controller
 * boundary (NodeController + AccessResolver); this Action stays pure.
 */
final readonly class UploadFileAction
{
    public function __construct(
        private BlobStore $blobs,
        private FileStoreRepository $files,
        private FileVersionStoreRepository $versions,
        private NodeStoreRepository $nodeStore,
        private NodeReadRepository $nodeRead,
        private StorageStoreRepository $storageStore,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(UploadFileData $data): Node
    {
        return $this->metrics->timing(
            name: 'vaulter_upload_chunk_duration_seconds',
            operation: fn (): Node => $this->executeUpload($data),
            labels: [
                'protocol' => 'single',
                'tier' => LoadTier::label(),
            ],
        );
    }

    private function executeUpload(UploadFileData $data): Node
    {
        $probedSize = $this->probeSize($data->stream);
        $previousSize = $this->previousHeadSize($data);

        // Eager quota — the delta a new version adds is (new − old); a brand-new
        // file adds its full size. Checked BEFORE we commit any bytes (§1.5). The
        // probe is best-effort for non-seekable streams; the real used_bytes
        // adjustment below uses the committed blob's authoritative size.
        $this->assertWithinQuota(data: $data, delta: $probedSize - $previousSize);

        // A new file gets a normalized name under a resolved folder (the root when no
        // destination is given); a new version keeps the node's place and name.
        $name = $data->isNewVersion() ? null : NodeName::normalize($data->resolvedName());
        $parentId = $data->isNewVersion() ? null : $this->tree->destinationId(storage: $data->storage, into: $data->into);

        return $this->database->transaction(function () use ($data, $previousSize, $name, $parentId): Node {
            $this->database->lockTreeShared($data->storage->id);

            if ($name !== null && $parentId !== null) {
                $this->assertDestination(data: $data, parentId: $parentId, name: $name);
            }

            // A new version locks its target before the CAS lock, as every writer takes its
            // node row before CAS, and measures the delta from the locked row; a target
            // deleted meanwhile is not found rather than given new blob edges.
            $target = $data->targetNode;

            if ($target instanceof Node) {
                $this->tree->sync($target, $this->tree->locked($this->tree->lock([$target->id]), $target->id));
                $previousSize = $target->size_bytes;
            }

            $blob = $this->blobs->commit(
                stream: $data->stream,
                tenantId: $this->tenantId($data),
                storageId: $this->storageIdForScope($data),
                disk: $data->storage->disk,
            );

            $size = $blob->size_bytes;

            $this->metrics->counter(
                name: 'vaulter_upload_bytes_total',
                amount: $size,
                labels: ['protocol' => 'single'],
            );

            $node = $name === null || $parentId === null
                ? $this->commitNewVersion(data: $data, blob: $blob, size: $size)
                : $this->commitNewFile(data: $data, blob: $blob, size: $size, name: $name, parentId: $parentId);

            $delta = $size - $previousSize;

            if ($delta !== 0) {
                $this->storageStore->adjustUsedBytes(storage: $data->storage, delta: $delta);
            }

            return $node;
        });
    }

    /**
     * New-file path (§2.4): node(kind=file) → file → version_no=1 → edge → repoint.
     */
    private function commitNewFile(UploadFileData $data, Blob $blob, int $size, string $name, int|string $parentId): Node
    {
        $node = $this->nodeStore->create([
            'storage_id' => $data->storage->id,
            'parent_id' => $parentId,
            'kind' => NodeKind::File,
            'name' => $name,
            'mime_type' => $data->mimeType,
            'extension' => $this->extension($data->originalName),
            'size_bytes' => $size,
            'owner_id' => $data->actorId,
            'created_by' => $data->actorId,
        ]);

        $file = $this->files->create([
            'node_id' => $node->id,
            'mime_type' => $data->mimeType,
            'size_bytes' => $size,
            'version_count' => 0,
        ]);

        $version = $this->makeVersion(data: $data, file: $file, blob: $blob, size: $size, versionNo: 1);

        $this->blobs->attach($version, $blob);

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $data->mimeType,
            sizeBytes: $size,
        );

        $this->nodeStore->update($node, [
            'file_id' => $file->id,
            'current_version_id' => $version->id,
        ]);

        event(new FileUploaded(node: $node->refresh(), file: $file, version: $version, actorId: $data->actorId));

        return $node;
    }

    /**
     * New-version path (§2.4): bump version_no, new version + edge against the
     * (deduped) blob, mark the prior head superseded, repoint file + node heads.
     */
    private function commitNewVersion(UploadFileData $data, Blob $blob, int $size): Node
    {
        $node = $data->targetNode;

        if (!$node instanceof Node) {
            // Defensive: isNewVersion() already guaranteed this; keeps PHPStan honest.
            throw new LogicException('[Vaulter] A new-version upload requires a target node.');
        }

        /** @var File $file */
        $file = $node->file()->firstOrFail();

        $previousHead = $file->currentVersion()->first();

        $version = $this->makeVersion(
            data: $data,
            file: $file,
            blob: $blob,
            size: $size,
            versionNo: $this->versions->nextVersionNo($file),
        );

        $this->blobs->attach($version, $blob);

        if ($previousHead instanceof FileVersion) {
            $this->versions->markSuperseded($previousHead);
        }

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $data->mimeType ?? $file->mime_type,
            sizeBytes: $size,
        );

        $this->nodeStore->repointHead(
            node: $node,
            currentVersionId: $version->id,
            mimeType: $data->mimeType ?? $node->mime_type,
            extension: $node->extension,
            sizeBytes: $size,
        );

        event(new NodeVersionCommitted(
            node: $node->refresh(),
            file: $file,
            version: $version,
            versionNo: $version->version_no,
            actorId: $data->actorId,
        ));

        return $node;
    }

    private function makeVersion(
        UploadFileData $data,
        File $file,
        Blob $blob,
        int $size,
        int $versionNo,
    ): FileVersion {
        return $this->versions->create([
            'file_id' => $file->getKey(),
            'version_no' => $versionNo,
            'blob_id' => $blob->getKey(),
            'content_sha256' => $blob->sha256,
            'size_bytes' => $size,
            'mime_type' => $data->mimeType,
            'original_name' => $data->originalName,
            'status' => 'committed',
            'created_by' => $data->actorId,
            'committed_at' => now(),
        ]);
    }

    /**
     * Reject the upload BEFORE committing bytes when it would push used_bytes past
     * quota_bytes (§1.5). A null quota is unlimited; a non-positive delta (a
     * smaller new version) never trips the check.
     */
    private function assertWithinQuota(UploadFileData $data, int $delta): void
    {
        $quota = $data->storage->quota_bytes;

        if ($quota === null || $delta <= 0) {
            return;
        }

        if ($data->storage->used_bytes + $delta > $quota) {
            throw QuotaExceededException::forStorage(
                storageId: $data->storage->id,
                quotaBytes: $quota,
                usedBytes: $data->storage->used_bytes,
                incomingBytes: $delta,
            );
        }
    }

    /**
     * The destination folder is locked before the bytes are committed, so it cannot
     * be trashed or replaced by a file between the check and the insert.
     */
    private function assertDestination(UploadFileData $data, int|string $parentId, string $name): void
    {
        $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
        if (!$this->tree->sameStorage($parent, $data->storage->id)) {
            throw CrossStorageMoveException::between(fromStorageId: $data->storage->id, toStorageId: $parent->storage_id);
        }

        $this->tree->assertActiveFolder($parent);

        if ($this->nodeRead->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $name)) {
            throw NodeNameConflictException::forName(name: $name, parentId: $parentId);
        }
    }

    private function previousHeadSize(UploadFileData $data): int
    {
        $target = $data->targetNode;

        return $target instanceof Node ? $target->size_bytes : 0;
    }

    private function tenantId(UploadFileData $data): ?int
    {
        $tenantId = $data->storage->tenant_id;

        return is_numeric($tenantId) ? (int) $tenantId : null;
    }

    /**
     * The storage id handed to the CAS only matters under dedup_scope=storage; for
     * other scopes BlobStore ignores it, so passing it unconditionally is safe.
     */
    private function storageIdForScope(UploadFileData $data): ?int
    {
        $storageId = $data->storage->id;

        return is_numeric($storageId) ? (int) $storageId : null;
    }

    /**
     * Probe the staged stream's byte length without consuming it (the ContentStore
     * re-reads it from the start). Seekable streams report fstat size; a
     * non-seekable stream falls back to 0 here and the committed blob's size is the
     * truth (used for accounting after commit).
     */
    private function probeSize(mixed $stream): int
    {
        if (!is_resource($stream)) {
            return 0;
        }

        $stats = fstat($stream);

        return is_array($stats) ? (int) $stats['size'] : 0;
    }

    private function extension(string $name): ?string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $ext === '' ? null : mb_strtolower($ext);
    }
}
