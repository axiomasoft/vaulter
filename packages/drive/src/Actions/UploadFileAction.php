<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions;

use LogicException;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\UploadFileData;
use Vaulter\Drive\Events\FileUploaded;
use Vaulter\Drive\Events\NodeVersionCommitted;
use Vaulter\Drive\Exceptions\CrossStorageMoveException;
use Vaulter\Drive\Exceptions\QuotaExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\LoadTier;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * Commit an upload into the Drive (§2.4 commit flow): stage → quota → BlobStore.commitStaged
 * (content-addressed dedup) → File + FileRevision(version_no) + version↔blob edge
 * → file node (current_version_id, mime, size, extension).
 *
 * Quota (§1.5 eager): the incoming size is checked against the storage's
 * quota_bytes by a conditional UPDATE WITHIN the transaction, before CAS promotion.
 * Preparation happens once outside SQL so transaction retries reuse the same bytes.
 *
 * Two paths share one Action:
 *   - new file → create Node(kind=file) + File + FileRevision(version_no=1);
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
        private ContentStore $content,
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $versions,
        private NodeStoreRepository $nodeStore,
        private NodeReadRepository $nodeRead,
        private DriveStoreRepository $storageStore,
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
        $name = $data->isNewVersion() ? null : NodeName::normalize($data->resolvedName());
        $parentId = $data->isNewVersion() ? null : $this->tree->destinationId(storage: $data->storage, into: $data->into);
        $staged = $this->content->stage($data->stream);

        try {
            // Revision deltas must use the locked head; a stale caller cannot reject them.
            if (!$data->isNewVersion()) {
                $this->assertWithinQuota(data: $data, delta: $staged->sizeBytes());
            }

            $node = $this->database->transaction(function () use ($data, $staged, $name, $parentId): Node {
                // Lock order: drive → nodes → file → quota → CAS, so concurrent writers never deadlock.
                $this->database->lockTreeShared($data->storage->id);

                if ($name !== null && $parentId !== null) {
                    $this->assertDestination(data: $data, parentId: $parentId, name: $name);
                }

                $target = $data->targetNode;
                $file = null;
                $previousHead = null;

                if ($target instanceof Node) {
                    $this->tree->sync($target, $this->tree->locked($this->tree->lock([$target->id]), $target->id));
                    /** @var File $currentFile */
                    $currentFile = $target->file()->firstOrFail();
                    $file = $this->files->lockForUpdate($currentFile);
                    $previousHead = $file->currentVersion()->first();
                }

                $size = $staged->sizeBytes();
                $delta = $size - ($previousHead->size_bytes ?? 0);

                if ($delta > 0) {
                    $this->storageStore->reserveBytes(storage: $data->storage, delta: $delta);
                } elseif ($delta < 0) {
                    $this->storageStore->adjustUsedBytes(storage: $data->storage, delta: $delta);
                }

                $storageId = $data->storage->getKey();
                if (!is_int($storageId) && !is_string($storageId)) {
                    throw new LogicException('An upload requires a persisted storage identifier.');
                }

                $blob = $this->blobs->commitStaged(
                    staged: $staged,
                    scope: BlobScope::forUpload(
                        Config::dedupScope(),
                        $data->storage->tenant_id === null ? null : (string) $data->storage->tenant_id,
                        (string) $storageId,
                    ),
                    disk: $data->storage->disk,
                );

                return $file instanceof File
                    ? $this->commitNewVersion(data: $data, file: $file, previousHead: $previousHead, blob: $blob, size: $size)
                    : $this->commitNewFile(data: $data, blob: $blob, size: $size, name: $name ?? '', parentId: $parentId ?? throw new LogicException('An upload requires a destination.'));
            }, 3);

            $this->metrics->counter(
                name: 'vaulter_upload_bytes_total',
                amount: $staged->sizeBytes(),
                labels: ['protocol' => 'single'],
            );

            return $node;
        } catch (CoreQuotaExceededException $e) {
            throw $e instanceof QuotaExceededException ? $e : new QuotaExceededException($e->getMessage(), $e->getCode(), $e);
        } finally {
            $staged->discard();
        }
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
            versionNo: $version->version_no,
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
    private function commitNewVersion(UploadFileData $data, File $file, ?FileRevision $previousHead, Blob $blob, int $size): Node
    {
        $node = $data->targetNode;

        if (!$node instanceof Node) {
            // Defensive: isNewVersion() already guaranteed this; keeps PHPStan honest.
            throw new LogicException('[Vaulter] A new-version upload requires a target node.');
        }

        $version = $this->makeVersion(
            data: $data,
            file: $file,
            blob: $blob,
            size: $size,
            versionNo: $file->version_count + 1,
        );

        $this->blobs->attach($version, $blob);

        if ($previousHead instanceof FileRevision) {
            $this->versions->markSuperseded($previousHead);
        }

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $data->mimeType ?? $file->mime_type,
            sizeBytes: $size,
            versionNo: $version->version_no,
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
    ): FileRevision {
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

    private function extension(string $name): ?string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $ext === '' ? null : mb_strtolower($ext);
    }
}
