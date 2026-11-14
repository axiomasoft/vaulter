<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use Closure;
use LogicException;
use Psr\Http\Message\StreamInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Content\ScanAdmission;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Files\IngestReceipt;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Exceptions\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Drive\Repositories\FileRevisionStoreRepository;
use Vaulter\Drive\Repositories\FileStoreRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Drive\Uploads\Inspection\UploadInspection;
use Vaulter\Drive\Uploads\PreparedUpload;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Events\EventRecorder;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;

/**
 * Commit an upload into the Drive (commit flow): stage → quota → BlobStore.commitStaged
 * (content-addressed dedup) → File + FileRevision(number) + version↔blob edge
 * → file node (current_revision_id, mime, size, extension).
 *
 * Quota (eager): every retained revision counts with its full
 * logical size, so the incoming size is reserved against the storage's quota_bytes
 * by a conditional UPDATE WITHIN the transaction, before CAS promotion — whether the
 * bytes deduplicate or not. Preparation happens once outside SQL so transaction
 * retries reuse the same bytes.
 *
 * Two paths share one Action:
 *   - new file → create Node(kind=file) + File + FileRevision(number=1);
 *   - new version (upload onto an existing file node) → bump number, repoint
 *     current_revision_id, mark the prior head superseded; the prior head stays a
 *     retained revision, so used_bytes grows by the full new size.
 *
 * Operations authorize before this command; locked invariants remain in the Action.
 */
#[Internal]
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
        private DrivePolicyEnforcer $policy,
        private UploadInspection $inspection,
        private StagedUpload $staging,
        private NameConflictResolver $names,
        private NodeLocator $locator,
        private NodeSequence $sequence,
        private ScanAdmission $scans,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
        private RevisionBlobLinks $links,
        private NodeSnapshotMapper $nodeSnapshots,
        private FileRevisionSnapshotMapper $revisionSnapshots,
    ) {}

    public function execute(DriveContext $context, IngestFileInput $data): Node
    {
        return $this->receipt($context, $data)->node;
    }

    /** Like {@see execute()}, plus the revision this call created (the shared head may already have moved on). */
    public function receipt(DriveContext $context, IngestFileInput $data): IngestReceipt
    {
        return $this->metrics->timing(
            name: 'vaulter_upload_chunk_duration_seconds',
            operation: fn (): IngestReceipt => $this->executeUpload($context, $data),
            labels: [
                'protocol' => 'single',
                'tier' => LoadTier::label(),
            ],
        );
    }

    public function prepare(DriveContext $context, IngestFileInput $data): PreparedUpload
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

    private function executeUpload(DriveContext $context, IngestFileInput $data): IngestReceipt
    {
        $name = $data->isNewVersion() ? null : $this->policy->name(drive: $context->drive, name: $data->resolvedName());
        $parentId = $data->isNewVersion() ? null : $this->tree->destinationId(storage: $data->storage, into: $data->into);
        $ownsPreparation = $data->prepared === null;
        $prepared = $data->prepared ?? $this->prepare($context, $data);
        $facts = $prepared->facts;
        $data = new IngestFileInput(storage: $context->drive, stream: $data->stream, originalName: $data->originalName,
            mimeType: $facts->detectedMimeType, into: $data->into, name: $data->name, targetNode: $data->targetNode,
            expectedSequence: $data->expectedSequence, onConflict: $data->onConflict, prepared: $prepared, declaredMimeType: $facts->declaredMimeType, clientModifiedAt: $data->clientModifiedAt);
        $staged = $prepared->staged;

        try {
            // A fast refusal before any lock; the conditional UPDATE below is authoritative.
            $this->assertWithinQuota(data: $data, delta: $staged->sizeBytes());

            $receipt = $this->database->transaction(function () use ($context, $data, $staged, $name, $parentId): IngestReceipt {
                // Lock order: drive → nodes → file → quota → CAS, so concurrent writers never deadlock.
                $this->database->lockAggregateShared('drives', $data->storage->id);
                $this->lifecycle->assertWritable($context);

                if ($name !== null && $parentId !== null) {
                    $this->assertDestination(context: $context, data: $data, parentId: $parentId);
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
                if ($size > 0) {
                    $this->storageStore->reserveBytes(storage: $data->storage, delta: $size);
                }

                $storageId = $data->storage->getKey();
                if (!is_int($storageId) && !is_string($storageId)) {
                    throw new LogicException('An upload requires a persisted storage identifier.');
                }

                // One scope for the commit and a re-commit: 'off' mints a new scope per call.
                $scope = BlobScope::forUpload(
                    $this->mediaConfig->dedupScope()->value,
                    $data->storage->tenant_id === null ? null : (string) $data->storage->tenant_id,
                    (string) $storageId,
                );
                // The store answers with a snapshot; the row it describes is read back by id in this transaction to be linked.
                $commit = fn (): Blob => $this->committedRow($this->blobs->commitStaged(staged: $staged, scope: $scope, disk: $data->storage->disk));
                $blob = $commit();

                return $file instanceof File
                    ? $this->commitNewVersion(context: $context, data: $data, file: $file, previousHead: $previousHead, blob: $blob, commit: $commit, size: $size, admissionSequence: $admissionSequence)
                    : $this->commitNewFile(context: $context, data: $data, blob: $blob, commit: $commit, size: $size, name: $name ?? '', parentId: $parentId ?? throw new LogicException('An upload requires a destination.'), admissionSequence: $admissionSequence);
            }, 3);

            $this->metrics->counter(
                name: 'vaulter_upload_bytes_total',
                amount: $staged->sizeBytes(),
                labels: ['protocol' => 'single'],
            );

            return $receipt;
        } catch (CoreQuotaExceededException $e) {
            throw $e instanceof QuotaExceededException ? $e : new QuotaExceededException($e->getMessage(), $e->getCode(), $e);
        } finally {
            if ($ownsPreparation) {
                $prepared->discard();
            }
        }
    }

    private function committedRow(BlobSnapshot $blob): Blob
    {
        $model = $this->mediaConfig->blobModel();

        return $model::query()->whereKey($blob->id)->firstOrFail();
    }

    /**
     * New-file path: node(kind=file) → file → number=1 → edge → repoint.
     *
     * @param Closure(): Blob $commit commits the staged bytes again
     */
    private function commitNewFile(DriveContext $context, IngestFileInput $data, Blob $blob, Closure $commit, int $size, string $name, int|string $parentId, int $admissionSequence): IngestReceipt
    {
        $node = $this->nodeStore->create([
            'drive_id' => $data->storage->id,
            'parent_id' => $parentId,
            'kind' => NodeKind::File,
            'name' => $name,
            'mime_type' => $data->mimeType,
            'extension' => $this->extension($data->originalName),
            'size_bytes' => $size,
            'created_by' => $context->actor->ref()->id,
            'content_modified_at' => now(),
            'content_modified_by' => $context->actor->ref()->id,
        ]);

        $file = $this->files->create([
            'node_id' => $node->id,
            'mime_type' => $data->mimeType,
            'size_bytes' => $size,
            'revision_count' => 0,
        ]);

        $version = $this->makeVersion(context: $context, data: $data, file: $file, blob: $blob, size: $size, versionNo: 1, admissionSequence: $admissionSequence);

        $this->link(version: $version, blob: $blob, commit: $commit);

        $this->files->repointHead(
            file: $file,
            currentVersionId: $version->id,
            mimeType: $data->mimeType,
            sizeBytes: $size,
            versionNo: $version->number,
        );

        $this->scans->admit($context, $version);

        $this->recorder->record(new FileUploaded(nodeSnapshots: $this->nodeSnapshots, revisionSnapshots: $this->revisionSnapshots, node: $node->refresh(), file: $file, version: $version, actor: $context->actor->ref(), correlationId: $context->correlationId));

        return new IngestReceipt($node, $version);
    }

    /**
     * New-version path: bump number, new version + edge against the
     * (deduped) blob, mark the prior head superseded, repoint file + node heads.
     *
     * @param Closure(): Blob $commit commits the staged bytes again
     */
    private function commitNewVersion(DriveContext $context, IngestFileInput $data, File $file, ?FileRevision $previousHead, Blob $blob, Closure $commit, int $size, int $admissionSequence): IngestReceipt
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

        $this->link(version: $version, blob: $blob, commit: $commit);

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
            actorId: $context->actor->ref()->id,
        );

        $this->sequence->bump($node);

        $this->scans->admit($context, $version);

        $this->recorder->record(new FileRevisionAdded(nodeSnapshots: $this->nodeSnapshots, revisionSnapshots: $this->revisionSnapshots,
            node: $node->refresh(),
            file: $file,
            version: $version,
            versionNo: $version->number,
            actor: $context->actor->ref(), correlationId: $context->correlationId,
        ));

        return new IngestReceipt($node, $version);
    }

    /**
     * Link the revision to its blob: edge + pin (D23). The pin is refused only when the bytes went with a sweep
     * between the commit and the pin; this upload still holds them, so it commits them again — the row is
     * resurrected and the object promoted under the same lock — and pins that.
     *
     * @param Closure(): Blob $commit
     */
    private function link(FileRevision $version, Blob $blob, Closure $commit): void
    {
        try {
            $this->links->attach($version, $blob);
        } catch (BlobUnavailableException) {
            $again = $commit();
            if (Identity::stringify($again->getKey()) !== Identity::stringify($blob->getKey())) {
                throw new LogicException('[Vaulter] A re-commit of the same bytes in the same scope returned another blob.');
            }
            $this->links->attach($version, $again);
        }
    }

    private function makeVersion(
        DriveContext $context,
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
            'client_modified_at' => $data->clientModifiedAt,
        ]);
    }

    /**
     * Reject the upload BEFORE committing bytes when it would push used_bytes past
     * quota_bytes. A null quota is unlimited; an empty upload never trips
     * the check.
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
     * be trashed or replaced by a file between the check and the insert, nor fill
     * past the drive policy's children limit.
     */
    private function assertDestination(DriveContext $context, IngestFileInput $data, int|string $parentId): void
    {
        $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
        if (!$this->tree->sameStorage($parent, $data->storage->id)) {
            throw CrossDriveOperationException::between(fromStorageId: $data->storage->id, toStorageId: $parent->drive_id);
        }

        $this->tree->assertActiveFolder($parent);
        $this->policy->assertChildren(drive: $context->drive, parentId: $parentId);
        $this->policy->assertDepth(drive: $context->drive, parent: $parent);
    }

    private function extension(string $name): ?string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $ext === '' ? null : mb_strtolower($ext);
    }
}
