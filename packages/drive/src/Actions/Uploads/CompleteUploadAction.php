<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use InvalidArgumentException;
use RuntimeException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Content\UploadCleanupReconciler;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Exceptions\Uploads\UploadContentMismatchException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Uploads\PreparedUpload;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Support\NodeName;

#[Internal]
final readonly class CompleteUploadAction
{
    public function __construct(
        private IngestFileAction $ingest,
        private UploadSessionStoreRepository $sessions,
        private StagedUpload $staged,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private UploadCleanupReconciler $cleanup,
    ) {}

    public function execute(DriveContext $context, UploadSession $session): Node
    {
        // This transaction owns the staged-byte readers, so it never retries itself, and
        // the transactions nested below cannot either (Laravel retries only the outermost
        // level). A deadlock/serialization failure is retried by UploadOperations::complete()
        // through UploadSessionManager::withSession(attempts: 3) once everything rolled back.
        return $this->database->transaction(function () use ($context, $session): Node {
            $locked = $this->sessions->acquireForPatch($session);
            $this->assertOwnership(context: $context, session: $locked);
            if ($locked->status === UploadStatus::Committed && $locked->input_fingerprint === null) {
                $this->backfillLegacy(context: $context, session: $locked);
            }
            $this->authorizeSession(context: $context, session: $locked);
            if ($locked->status === UploadStatus::Committed) {
                $result = $this->result(context: $context, session: $locked);
                // Replay proves the same current mutation right as the original request did.
                $this->authorizeMutation(context: $context, session: $locked);
                $revision = $result->file()->firstOrFail()->versions()->whereKey($locked->result_revision_id)->firstOrFail();
                // The caller's session object carries the completion intent being replayed; the
                // locked row only proves what was committed. Comparing the two detects a changed intent.
                $hash = $this->fingerprint(session: $session, sha256: $revision->sha256, size: $revision->size_bytes, mime: $revision->mime_type, name: $revision->original_name);
                if ($locked->input_fingerprint === null || !hash_equals($locked->input_fingerprint, $hash)) {
                    throw UploadConflictException::forClosedSession($locked->upload_id);
                }
                $this->scheduleCleanup($locked);

                return $result;
            }
            if ($locked->status !== UploadStatus::Assembled) {
                throw UploadConflictException::forClosedSession($locked->upload_id);
            }
            $stream = $this->staged->readStream($locked);
            $prepared = null;
            try {
                if (!is_resource($stream)) {
                    throw new RuntimeException("Cannot commit upload session [{$locked->upload_id}]: no staged bytes are present.");
                }
                $input = $this->input(context: $context, session: $locked, stream: $stream);
                try {
                    $prepared = $this->ingest->prepare(context: $context, data: $input);
                } catch (UploadRejectedException $exception) {
                    if ($exception->reason !== 'mime_mismatch') {
                        throw $exception;
                    }
                    throw UploadContentMismatchException::forSession(uploadId: $locked->upload_id, declaredMime: (string) $locked->mime_type);
                }
                $facts = $prepared->facts;
                if (($locked->total_size_bytes !== null && $facts->size !== $locked->total_size_bytes)
                    || ($locked->expected_sha256 !== null && !hash_equals(strtolower($locked->expected_sha256), $facts->sha256))
                    || $facts->size !== $locked->received_bytes) {
                    throw UploadConflictException::forClosedSession($locked->upload_id);
                }
                $fingerprint = $this->fingerprint(session: $locked, sha256: $facts->sha256, size: $facts->size, mime: $facts->detectedMimeType, name: $facts->name);
                $receipt = $this->ingest->receipt(context: $context, data: $this->input(context: $context, session: $locked, stream: $stream, prepared: $prepared));
                $this->sessions->markCommitted(session: $locked, assembledBlobId: $receipt->revision->blob_id ?? throw UploadConflictException::forClosedSession($locked->upload_id), resultNodeId: $receipt->node->id, resultRevisionId: $receipt->revision->id, inputFingerprint: $fingerprint);
                $this->scheduleCleanup($locked);

                return $receipt->node;
            } finally {
                try {
                    $prepared?->discard();
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
        }, attempts: 1);
    }

    /** Authorize freshly reloaded ownership, tenant and the current target. */
    public function authorizeSession(DriveContext $context, UploadSession $session): void
    {
        $this->assertOwnership(context: $context, session: $session);
        if ($session->status === UploadStatus::Committed) {
            $this->result(context: $context, session: $session);

            return;
        }
        $this->authorizeMutation(context: $context, session: $session);
    }

    /** The right the original request needed: Edit on the target file, or Create in the parent. */
    private function authorizeMutation(DriveContext $context, UploadSession $session): void
    {
        $drive = $context->drive->newQuery()->whereKey($context->driveId())->first() ?? throw NodeNotFoundException::forId('drive');
        if ($session->isNewVersion()) {
            $this->guard->authorize(context: $context, ability: Ability::Edit, node: $this->locator->find(context: $context, key: $session->target_node_id ?? 'missing'));
        } else {
            $this->guard->authorizeCreate(context: $context, parent: $this->locator->find(context: $context, key: $session->target_parent_id ?? $drive->root_node_id ?? throw NodeNotFoundException::forId('root')));
        }
    }

    private function assertOwnership(DriveContext $context, UploadSession $session): void
    {
        $drive = $context->drive->newQuery()->whereKey($context->driveId())->first() ?? throw NodeNotFoundException::forId('drive');
        if ((string) $session->drive_id !== (string) $context->driveId() || (string) $session->tenant_id !== (string) $drive->tenant_id) {
            throw NodeNotFoundException::forId('upload');
        }
        if (!$context->actor->isSystem()) {
            $type = ($session->metadata ?? [])['actor_type'] ?? null;
            if ($session->created_by === null || (string) $session->created_by !== (string) $context->actor->ref()->id || ($type !== null && $type !== $context->actor->ref()->type)) {
                throw PermissionDeniedException::forAbility('upload-session-owner');
            }
        }
    }

    private function backfillLegacy(DriveContext $context, UploadSession $session): void
    {
        if ($session->result_node_id === null || $session->result_revision_id === null || $session->assembled_blob_id === null) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }
        $node = $this->locator->find(context: $context, key: $session->result_node_id);
        $this->guard->authorize(context: $context, ability: Ability::View, node: $node);
        $revision = $node->file()->first()?->versions()->whereKey($session->result_revision_id)->first();
        $blob = $revision?->blob()->first();
        if ($revision === null || $blob === null || !in_array($revision->status, ['committed', 'superseded'], true)
            || (string) $revision->blob_id !== (string) $session->assembled_blob_id || $revision->sha256 !== $blob->sha256
            || $revision->size_bytes !== $blob->size_bytes || $revision->manifest_blob_id !== null || $blob->chunked
            || $revision->blobs()->count() !== 1 || !$revision->blobs()->whereKey($blob->id)->exists()) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }
        $hash = $this->fingerprint(session: $session, sha256: $revision->sha256, size: $revision->size_bytes, mime: $revision->mime_type, name: $revision->original_name);
        $session->forceFill(['result_blob_id' => $blob->id, 'input_fingerprint' => $hash,
            'cleanup_pending' => true, 'cleanup_available_at' => now()])->save();
    }

    private function result(DriveContext $context, UploadSession $session): Node
    {
        if ($session->result_node_id === null || $session->result_revision_id === null || $session->result_blob_id === null || (string) $session->result_blob_id !== (string) $session->assembled_blob_id) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }
        $node = $this->locator->find(context: $context, key: $session->result_node_id);
        $this->guard->authorize(context: $context, ability: Ability::View, node: $node);
        $revision = $node->file()->first()?->versions()->whereKey($session->result_revision_id)->first();
        if ($revision === null || !in_array($revision->status, ['committed', 'superseded'], true) || (string) $revision->blob_id !== (string) $session->result_blob_id) {
            throw UploadConflictException::forClosedSession($session->upload_id);
        }

        return $node;
    }

    private function scheduleCleanup(UploadSession $session): void
    {
        // Only the actual outer commit may release protocol bytes. The durable
        // pending flag survives crashes and a failed callback cannot undo success.
        $this->database->afterCommit(fn () => $this->cleanup->cleanup($session));
    }

    private function expectation(UploadSession $session): ?int
    {
        $value = ($session->metadata ?? [])['expected_sequence'] ?? null;
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new InvalidArgumentException('Expected sequence must be a nonnegative integer.');
        }

        return $value;
    }

    private function fingerprint(UploadSession $session, string $sha256, int $size, ?string $mime, string $name): string
    {
        return hash('sha256', json_encode([
            $session->protocol->value, $session->upload_id,
            (string) $session->created_by, ($session->metadata ?? [])['actor_type'] ?? null,
            (string) $session->drive_id, (string) $session->tenant_id,
            (string) $session->target_node_id, (string) $session->target_parent_id,
            $session->target_name === null ? null : NodeName::normalize($session->target_name),
            NodeName::normalize($name), $this->normalizeMime($session->mime_type),
            strtolower((string) $session->expected_sha256), $session->total_size_bytes,
            $this->expectation($session), $sha256, $size, $mime,
        ], JSON_THROW_ON_ERROR));
    }

    private function normalizeMime(?string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime ?? '', 2)[0]));

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    /** @param resource $stream */
    private function input(DriveContext $context, UploadSession $session, mixed $stream, ?PreparedUpload $prepared = null): IngestFileInput
    {
        $target = $session->target_node_id === null ? null : $this->locator->find(context: $context, key: $session->target_node_id);
        $parent = $target !== null ? null : $this->locator->find(context: $context, key: $session->target_parent_id ?? $context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));

        return new IngestFileInput(storage: $context->drive, stream: $stream,
            originalName: $session->target_name ?? $target->name ?? 'upload.bin',
            mimeType: $session->mime_type, declaredMimeType: $session->mime_type,
            into: $parent, name: $session->target_name, targetNode: $target,
            expectedSequence: $this->expectation($session), prepared: $prepared);
    }
}
