<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Collaboration;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Support\LoadTier;
use Vaulter\Support\TreeGuard;

/**
 * Persist a Yjs snapshot (master plan §7 / ADR-0001 / M4b) — the write the Node
 * Hocuspocus hub's debounced onStoreDocument drives. The Yjs binary CRDT state is the
 * SOURCE OF TRUTH and becomes the working state verbatim; the optional TipTap JSON is
 * the DERIVED projection (we never reconstruct Yjs from JSON — when the hub omits the
 * projection, the current projection stays and only the Yjs state advances).
 *
 * A room may only write what the autosave path may: on the locked row, a type that is
 * not installed, content written in a newer schema, or content its type reads only
 * partly refuses the whole store (neither Yjs nor JSON changes). The projection is
 * decoded by the document's type, with its size limit; without one, the JSON keeps
 * the schema version it was written in.
 *
 * An identical snapshot is a no-op. Checkpoints follow the same interval as the
 * autosave path. One outer transaction under T → N → D locks; a single after-commit
 * DocumentContentUpdated event integrates with the activity/outbox subscribers.
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
    ) {}

    /**
     * @throws DocumentTypeUnavailableException
     * @throws DocumentContentDegradedException
     * @throws InvalidDocumentContentException  when the type rejects the projection or it is too large
     */
    public function execute(PersistCollaborationStateInput $data): DocumentStateResult
    {
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
                if (!$this->tree->sameStorage($current, $driveId)) {
                    throw NodeNotFoundException::forId($nodeId);
                }
                if (!$current->kind->isDocument()) {
                    throw NotADocumentException::forNode($nodeId);
                }
                $node = $this->tree->sync($node, $current);
                $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
                $document->setRelation('node', $node);
                $type = $this->decoder->editableType($document);
                // The Yjs state is the source of truth and is unbounded here by design
                // (§7); only the projection is decoded and size-checked.
                $content = $data->content === null ? null : $this->decoder->decode($type, $data->content);

                $result = $this->writer->write($document, new DocumentStateChange(
                    cause: StateWriteCause::Collaboration,
                    content: $content,
                    schemaVersion: $content === null ? $document->schema_version : $type->schemaVersion(),
                    yjsState: $data->yjsState,
                    actorId: $data->actorId,
                ));

                if ($result->changed) {
                    event(new DocumentContentUpdated(
                        node: $node,
                        version: $result->checkpoint ?? $document->currentRevision()->firstOrFail(),
                        created: $result->checkpoint !== null,
                        actorId: $data->actorId,
                    ));
                }

                return $result;
            }),
            labels: ['tier' => LoadTier::label()],
        );
    }
}
