<?php

declare(strict_types=1);

namespace Vaulter\Actions\Drive;

use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\BlobStore;
use Vaulter\Data\UploadFileData;
use Vaulter\Enums\NodeKind;
use Vaulter\Events\FileUploaded;
use Vaulter\Events\NodeVersionCommitted;
use Vaulter\Exceptions\NodeNameConflictException;
use Vaulter\Exceptions\QuotaExceededException;
use Vaulter\Models\Blob;
use Vaulter\Models\File;
use Vaulter\Models\FileVersion;
use Vaulter\Models\Node;
use Vaulter\Repositories\FileStoreRepository;
use Vaulter\Repositories\FileVersionStoreRepository;
use Vaulter\Repositories\NodeReadRepository;
use Vaulter\Repositories\NodeStoreRepository;
use Vaulter\Repositories\StorageStoreRepository;

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
 * M2 authorize() seam: an `$this->authorize(...)` call slots in before staging.
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
    ) {}

    public function execute(UploadFileData $data): Node
    {
        // M2 seam: authorize 'upload' on the target node / destination parent here.

        $probedSize = $this->probeSize($data->stream);
        $previousSize = $this->previousHeadSize($data);

        // Eager quota — the delta a new version adds is (new − old); a brand-new
        // file adds its full size. Checked BEFORE we commit any bytes (§1.5). The
        // probe is best-effort for non-seekable streams; the real used_bytes
        // adjustment below uses the committed blob's authoritative size.
        $this->assertWithinQuota(data: $data, delta: $probedSize - $previousSize);

        if (!$data->isNewVersion()) {
            $this->assertNameAvailable($data);
        }

        return DB::transaction(function () use ($data, $previousSize): Node {
            $blob = $this->blobs->commit(
                stream: $data->stream,
                tenantId: $this->tenantId($data),
                storageId: $this->storageIdForScope($data),
                disk: $data->storage->disk,
            );

            $size = $blob->size_bytes;

            $node = $data->isNewVersion()
                ? $this->commitNewVersion(data: $data, blob: $blob, size: $size)
                : $this->commitNewFile(data: $data, blob: $blob, size: $size);

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
    private function commitNewFile(UploadFileData $data, Blob $blob, int $size): Node
    {
        $name = $data->resolvedName();

        $node = $this->nodeStore->create([
            'storage_id' => $data->storage->getKey(),
            'parent_id' => $data->into?->getKey(),
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
            return $this->commitNewFile(data: $data, blob: $blob, size: $size);
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

    private function assertNameAvailable(UploadFileData $data): void
    {
        $parentId = $data->into?->id;
        $name = $data->resolvedName();

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
