<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Collaboration;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Documents\Exceptions\CollaborationSeedConflictException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStateTooLargeException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;
use Vaulter\Support\TreeGuard;

/**
 * Persist a collaboration state — the write the hub's onStoreDocument (and its
 * one-time seed) drives. The binary Yjs state is the source of truth and becomes the
 * working state verbatim; the JSON is its projection: the one the hub sent, else the
 * type's projectFromState(), else the current JSON stays.
 *
 * Storage fence: the room's epoch is compared with collab_epoch on the row locked FOR
 * UPDATE, and a restore or an external replace moves that epoch under the same lock.
 * So a store from a room opened before a restore either commits before it (and is
 * replaced by it) or is refused with 409 — it never lands on top of the restored state,
 * however late the hub's debounce fires. A seed is written only while the document
 * has no Yjs state, so two hub instances cannot both build insertions from the JSON.
 *
 * A room may only write what the autosave path may: a type that is not installed or
 * cannot collaborate, content written in a newer schema, or content its type reads
 * only partly refuses the whole store. An identical state is a no-op. One transaction
 * under T → N → D locks; one after-commit DocumentContentUpdated event.
 */
final readonly class PersistCollaborationStateAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private ContentDecoder $decoder,
        private DocumentsConfig $documentsConfig,
    ) {}

    /**
     * @throws DocumentStateTooLargeException      above collaboration.max_state_bytes
     * @throws CollaborationEpochMismatchException the room is from another generation
     * @throws CollaborationSeedConflictException  a seed over an existing Yjs state
     * @throws CollaborationUnavailableException   the type cannot collaborate
     * @throws DocumentTypeUnavailableException
     * @throws DocumentContentDegradedException
     * @throws InvalidDocumentContentException     when the type rejects the projection or it is too large
     */
    public function execute(PersistCollaborationStateInput $data): DocumentStateResult
    {
        $limit = $this->documentsConfig->collaborationMaxStateBytes();
        if (strlen($data->yjsState) > $limit) {
            throw new DocumentStateTooLargeException(strlen($data->yjsState), $limit);
        }

        $node = $data->node;

        return $this->metrics->timing(
            name: 'vaulter_collab_snapshot_duration_seconds',
            operation: fn (): DocumentStateResult => $this->database->transaction(function () use ($node, $data): DocumentStateResult {
                $driveId = $node->getRawOriginal('drive_id');
                $nodeId = $node->getRawOriginal($node->getKeyName());
                if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === ''
                    || (!is_int($driveId) && !is_string($driveId)) || $driveId === '') {
                    throw NodeNotFoundException::forId('unpersisted');
                }
                $this->database->lockTreeShared($driveId);
                $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
                // A node trashed or purged while the room was open takes no more writes.
                if (!$this->tree->sameStorage($current, $driveId) || $current->trashed_at !== null) {
                    throw NodeNotFoundException::forId($nodeId);
                }
                if (!$current->kind->isDocument()) {
                    throw NotADocumentException::forNode($nodeId);
                }
                $node = $this->tree->sync($node, $current);
                $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
                $document->setRelation('node', $node);

                // The fence is checked first: a room of a past generation learns that
                // before anything else, and the hub closes it.
                if ($data->expectedEpoch !== null && $data->expectedEpoch !== $document->collab_epoch) {
                    throw new CollaborationEpochMismatchException(expected: $data->expectedEpoch, actual: $document->collab_epoch);
                }
                $type = $this->decoder->editableType($document);
                if (!$type instanceof SupportsCollaboration) {
                    throw CollaborationUnavailableException::unsupportedType($type->key());
                }
                if ($data->seed && $document->yjs_state !== null) {
                    throw new CollaborationSeedConflictException(Identity::stringify($nodeId));
                }
                $content = $data->content === null
                    ? $type->projectFromState($data->yjsState)
                    : $this->decoder->decode($type, $data->content);

                $result = $this->writer->write($document, new DocumentStateChange(
                    cause: $data->seed ? StateWriteCause::Seed : StateWriteCause::Collaboration,
                    content: $content,
                    schemaVersion: $content === null ? $document->schema_version : $type->schemaVersion(),
                    yjsState: $data->yjsState,
                    actorId: $data->actorId,
                    expectedEpoch: $data->expectedEpoch,
                ));

                if ($result->changed) {
                    event(new DocumentContentUpdated(
                        node: $node,
                        version: $result->checkpoint ?? $document->currentRevision()->firstOrFail(),
                        created: $result->checkpoint !== null,
                        stateVersion: $result->document->state_version,
                        epoch: $result->document->collab_epoch,
                        actorId: $data->actorId,
                    ));
                }

                return $result;
            }),
            labels: ['tier' => LoadTier::label()],
        );
    }
}
