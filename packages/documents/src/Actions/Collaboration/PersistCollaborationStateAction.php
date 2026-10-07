<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Collaboration;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
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
        private DocumentsConfig $documentsConfig,
        private TreeGuard $tree,
    ) {}

    public function execute(PersistCollaborationStateInput $data): DocumentStateResult
    {
        $node = $data->node;

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time. The Yjs binary state is the source of truth and is unbounded here by
        // design (§7); only the optional TipTap JSON projection is size-checked.
        $data->content?->ensureWithin($this->documentsConfig->maxContentSizeKb());

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

                $result = $this->writer->write($document, new DocumentStateChange(
                    cause: StateWriteCause::Collaboration,
                    content: $data->content,
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
