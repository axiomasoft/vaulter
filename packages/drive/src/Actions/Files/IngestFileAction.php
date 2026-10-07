<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use LogicException;
use Psr\Http\Message\StreamInterface;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\ScanAdmission;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Uploads\Inspection\UploadInspection;
use Vaulter\Drive\Uploads\PreparedUpload;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\NodeSequence;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\LoadTier;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * Commit an upload into the Drive (§2.4 commit flow): stage → quota → BlobStore.commitStaged
 * (content-addressed dedup) → File + FileRevision(number) + version↔blob edge
 * → file node (current_revision_id, mime, size, extension).
 *
 * Quota (§1.5 eager): the incoming size is checked against the storage's
 * quota_bytes by a conditional UPDATE WITHIN the transaction, before CAS promotion.
 * Preparation happens once outside SQL so transaction retries reuse the same bytes.
 *
 * Two paths share one Action:
 *   - new file → create Node(kind=file) + File + FileRevision(number=1);
 *   - new version (upload onto an existing file node) → bump number, repoint
 *     current_revision_id, mark the prior head superseded, and adjust used_bytes by
 *     the SIZE DELTA (new size − previous head size), not the full size.
 *
 * Operations authorize before this command; locked invariants remain in the Action.
 */
final readonly class IngestFileAction
{
    public function __construct(
        private BlobStore $blobs,
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $versions,
        private NodeStoreRepository $nodeStore,
        private DriveStoreRepository $storageStore,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private MediaConfig $mediaConfig,
        private DriveConfig $driveConfig,
        private UploadInspection $inspection,
        private StagedUpload $staging,
        private NameConflictResolver $names,
        private NodeLocator $locator,
        private NodeSequence $sequence,
        private ScanAdmission $scans,
    ) {}

    public function execute(OperationContext $context, IngestFileInput $data): Node
    {
        return $this->metrics->timing(
            name: 'vaulter_upload_chunk_duration_seconds',
            operation: fn (): Node => $this->executeUpload($context, $data),
            labels: [
                'protocol' => 'single',
                'tier' => LoadTier::label(),
            ],
        );
    }

    public function prepare(OperationContext $context, IngestFileInput $data): PreparedUpload
    {
        $source = $data->stream instanceof StreamInterface
            ? UploadSource::fromPsrStream($data->stream, $data->originalName)
            : UploadSource::fromStream($data->stream, $data->originalName);
        $prepared = $this->staging->stageSource($source, $this->inspection->maximum($context), $data->mimeType, $data->resolvedName());
        try {
            $this->inspection->inspect($prepared->facts, $context);

            return $prepared;
        } catch (\Throwable $exception) {
            $prepared->discard();
            throw $exception;
        }
    }

    private function executeUpload(OperationContext $context, IngestFileInput $data): Node
    {
        $name = $data->isNewVersion() ? null : NodeName::normalize(name: $data->resolvedName(), maxLength: $this->driveConfig->nameMaxLength());
        $parentId = $data->isNewVersion() ? null : $this->tree->destinationId(storage: $data->storage, into: $data->into);
        $ownsPreparation = $data->prepared === null;
        $prepared = $data->prepared ?? $this->prepare($context, $data);
        $facts = $prepared->facts;
        $data = new IngestFileInput(storage: $context->drive, stream: $data->stream, originalName: $data->originalName,
            mimeType: $facts->detectedMimeType, into: $data->into, name: $data->name, targetNode: $data->targetNode,
            expectedSequence: $data->expectedSequence, onConflict: $data->onConflict, prepared: $prepared, declaredMimeType: $facts->declaredMimeType);
        $staged = $prepared->staged;

        try {
            // Revision deltas must use the locked head; a stale caller cannot reject them.
            if (!$data->isNewVersion() && $data->onConflict !== OnNameConflict::AddRevision) {
                $this->assertWithinQuota(data: $data, delta: $staged->sizeBytes());
            }

            $node = $this->database->transaction(function () use ($context, $data, $staged, $name, $parentId): Node {
                // Lock order: drive → nodes → file → quota → CAS, so concurrent writers never deadlock.
                $this->database->lockTreeShared($data->storage->id);

                if ($name !== null && $parentId !== null) {
                    $this->assertDestination(data: $data, parentId: $parentId, name: $name);
                    $name = $this->names->resolve($context->drive, $parentId, $name, $data->onConflict);
                }

                $target = $data->targetNode === null ? null : $this->locator->find(context: $context, key: $data->targetNode);
                $file = null;
                $previousHead = null;

                if ($target instanceof Node) {
                    $this->tree->sync($data->targetNode, $target);
                    $target = $this->tree->sync($data->targetNode, $this->tree->locked($this->tree->lock([$target->id]), $target->id));
                    if ($target->trashed_at !== null) {
                        throw InvalidDestinationException::trashed($target);
                    }
                    /** @var File $currentFile */
                    $currentFile = $target->file()->firstOrFail();
                    $this->sequence->assertExpected(locked: $target, expected: $data->expectedSequence);
                    $file = $this->files->lockForUpdate($currentFile);
                    $previousHead = $file->currentRevision()->first();
                }

                $admissionSequence = $this->scans->next($context);

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
                        $this->mediaConfig->dedupScope(),
                        $data->storage->tenant_id === null ? null : (string) $data->storage->tenant_id,
                        (string) $storageId,
                    ),
                    disk: $data->storage->disk,
                );

                return $file instanceof File
                    ? $this->commitNewVersion(context: $context, data: $data, file: $file, previousHead: $previousHead, blob: $blob, size: $size, admissionSequence: $admissionSequence)
                    : $this->commitNewFile(context: $context, data: $data, blob: $blob, size: $size, name: $name ?? '', parentId: $parentId ?? throw new LogicException('An upload requires a destination.'), admissionSequence: $admissionSequence);
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
            if ($ownsPreparation) {
                $prepared->discard();
            }
        }
    }

    /**
     * New-file path (§2.4): node(kind=file) → file → number=1 → edge → repoint.
     */
    private function commitNewFile(OperationContext $context, IngestFileInput $data, Blob $blob, int $size, string $name, int|string $parentId, int $admissionSequence): Node
    {
        $node = $this->nodeStore->create([
            'drive_id' => $data->storage->id,
            'parent_id' => $parentId,
            'kind' => NodeKind::File,
            'name' => $name,
            'mime_type' => $data->mimeType,
            'extension' => $this->extension($data->originalName),
            'size_bytes' => $size,
            'owner_id' => $context->actor->ref()->id,
            'created_by' => $context->actor->ref()->id,
        ]);

        $file = $this->files->create([
            'node_id' => $node->id,
            'mime_type' => $data->mimeType,
            'size_bytes' => $size,
            'revision_count' => 0,
        ]);

        $version = $this->makeVersion(context: $context, data: $data, file: $file, blob: $blob, size: $size, versionNo: 1, admissionSequence: $admissionSequence);

        $this->blobs->attach($version, $blob);

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $data->mimeType,
            sizeBytes: $size,
            versionNo: $version->number,
        );

        $this->scans->admit($context, $version);

        event(new FileUploaded(node: $node->refresh(), file: $file, version: $version, actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));

        return $node;
    }

    /**
     * New-version path (§2.4): bump number, new version + edge against the
     * (deduped) blob, mark the prior head superseded, repoint file + node heads.
     */
    private function commitNewVersion(OperationContext $context, IngestFileInput $data, File $file, ?FileRevision $previousHead, Blob $blob, int $size, int $admissionSequence): Node
    {
        $node = $data->targetNode;

        if (!$node instanceof Node) {
            // Defensive: isNewVersion() already guaranteed this; keeps PHPStan honest.
            throw new LogicException('[Vaulter] A new-version upload requires a target node.');
        }

        $version = $this->makeVersion(
            context: $context, data: $data,
            file: $file,
            blob: $blob,
            size: $size,
            versionNo: $file->revision_count + 1,
            admissionSequence: $admissionSequence,
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
            versionNo: $version->number,
        );

        $this->nodeStore->cacheHead(
            node: $node,
            mimeType: $data->mimeType ?? $node->mime_type,
            extension: $node->extension,
            sizeBytes: $size,
        );

        $this->sequence->bump($node);

        $this->scans->admit($context, $version);

        event(new FileRevisionAdded(
            node: $node->refresh(),
            file: $file,
            version: $version,
            versionNo: $version->number,
            actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId,
        ));

        return $node;
    }

    private function makeVersion(
        OperationContext $context,
        IngestFileInput $data,
        File $file,
        Blob $blob,
        int $size,
        int $versionNo,
        int $admissionSequence,
    ): FileRevision {
        return $this->versions->create([
            'file_id' => $file->getKey(),
            'number' => $versionNo,
            'blob_id' => $blob->getKey(),
            'sha256' => $blob->sha256,
            'size_bytes' => $size,
            'mime_type' => $data->mimeType,
            'original_name' => $data->originalName,
            'declared_mime_type' => $data->declaredMimeType,
            'scan_admission_sequence' => $admissionSequence,
            'status' => 'committed',
            'created_by' => $context->actor->ref()->id,
            'committed_at' => now(),
        ]);
    }

    /**
     * Reject the upload BEFORE committing bytes when it would push used_bytes past
     * quota_bytes (§1.5). A null quota is unlimited; a non-positive delta (a
     * smaller new version) never trips the check.
     */
    private function assertWithinQuota(IngestFileInput $data, int $delta): void
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
    private function assertDestination(IngestFileInput $data, int|string $parentId, string $name): void
    {
        $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
        if (!$this->tree->sameStorage($parent, $data->storage->id)) {
            throw CrossDriveOperationException::between(fromStorageId: $data->storage->id, toStorageId: $parent->drive_id);
        }

        $this->tree->assertActiveFolder($parent);

    }

    private function extension(string $name): ?string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $ext === '' ? null : mb_strtolower($ext);
    }
}
