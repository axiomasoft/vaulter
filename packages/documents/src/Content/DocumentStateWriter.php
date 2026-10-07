<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Int64Counter;

/**
 * The only writer of a document's working state and its checkpoints. Callers are
 * Actions that already hold, inside one Vaulter transaction, the drive tree lock,
 * the node lock and the v_documents row FOR UPDATE (T → N → D), so every check
 * here is made against the row the write will change.
 *
 * Content has its own validators: state_version moves by exactly one per effective
 * write and collab_epoch whenever open collaboration rooms must not write back
 * (restore, an external replace). v_nodes.sequence is never touched — renaming a
 * document and editing it do not invalidate each other.
 *
 * @internal
 */
final readonly class DocumentStateWriter
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private DocumentRevisionStoreRepository $revisions,
        private NodeStoreRepository $nodes,
        private DocumentsConfig $documentsConfig,
        private VaulterDatabase $database,
    ) {}

    public function write(Document $locked, DocumentStateChange $change): DocumentStateResult
    {
        $this->assertTransaction();

        // Preconditions are checked before anything is written.
        if ($change->expectedEpoch !== null && $change->expectedEpoch !== $locked->collab_epoch) {
            throw new CollaborationEpochMismatchException(expected: $change->expectedEpoch, actual: $locked->collab_epoch);
        }

        if ($change->expectedStateVersion !== null) {
            if ($change->expectedStateVersion < 0) {
                throw new InvalidArgumentException('[Vaulter] An expected state version must be nonnegative.');
            }

            if ($change->expectedStateVersion !== $locked->state_version) {
                throw new StaleDocumentStateException(expected: $change->expectedStateVersion, actual: $locked->state_version);
            }
        }

        $hash = $change->content?->contentHash() ?? $locked->content_hash;
        if ($change->cause->skipsIdenticalWrite() && $hash === $locked->content_hash && $change->yjsState === $locked->yjs_state) {
            return new DocumentStateResult(changed: false, document: $locked, checkpoint: null, epochChanged: false);
        }

        // A restore, or a JSON replace of a document that has Yjs state, makes every
        // open room's history stale: the next epoch fences their stores off.
        $epochChanged = $change->cause === StateWriteCause::Restore
            || ($change->cause === StateWriteCause::Update && $locked->yjs_state !== null);

        $attributes = [
            'state_version' => Int64Counter::increment($locked->state_version),
            'collab_epoch' => $epochChanged ? Int64Counter::increment($locked->collab_epoch) : $locked->collab_epoch,
            'yjs_state' => $change->yjsState,
            'yjs_state_size' => $change->yjsState === null ? null : strlen($change->yjsState),
            'schema_version' => $change->schemaVersion,
            'updated_by' => $change->actorId,
        ];

        if ($change->content !== null) {
            $attributes['content'] = $change->content->toArray();
            $attributes['content_hash'] = $change->content->contentHash();
            $attributes['content_size'] = $change->content->sizeInBytes();
        }

        $this->documents->update($locked, $attributes);

        $checkpoint = null;
        if ($change->forceCheckpoint || $locked->current_revision_id === null || $this->intervalElapsed($locked)) {
            $checkpoint = $this->capture(
                document: $locked,
                label: $change->label,
                keepForever: $change->keepForever,
                autosave: !$change->forceCheckpoint,
                restoredFrom: $change->restoredFrom,
                actorId: $change->actorId,
            );
        }

        $this->nodes->touch($this->node($locked), $change->actorId);

        return new DocumentStateResult(changed: true, document: $locked, checkpoint: $checkpoint, epochChanged: $epochChanged);
    }

    /**
     * A named checkpoint of the current working state — taken even when nothing
     * changed since the last one. The working state and its validators stay as
     * they are.
     */
    public function checkpoint(Document $locked, ?string $label, bool $keepForever, int|string|null $actorId): DocumentRevision
    {
        $this->assertTransaction();

        return $this->capture(
            document: $locked,
            label: $label,
            keepForever: $keepForever,
            autosave: false,
            restoredFrom: null,
            actorId: $actorId,
        );
    }

    private function capture(
        Document $document,
        ?string $label,
        bool $keepForever,
        bool $autosave,
        ?DocumentRevision $restoredFrom,
        int|string|null $actorId,
    ): DocumentRevision {
        $number = $document->revision_count + 1;
        $now = Carbon::now();

        $revision = $this->revisions->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->getKey(),
            'number' => $number,
            'schema_version' => $document->schema_version,
            'content' => $document->content,
            'content_hash' => $document->content_hash,
            'content_size' => $document->content_size,
            'yjs_state' => $document->yjs_state,
            'yjs_state_size' => $document->yjs_state_size,
            'state_version' => $document->state_version,
            'label' => $label,
            'is_autosave' => $autosave,
            'keep_forever' => $keepForever,
            'status' => 'committed',
            'restored_from_revision_id' => $restoredFrom?->getKey(),
            'created_by' => $actorId,
            'created_at' => $now,
        ]);

        $this->documents->update($document, [
            'current_revision_id' => $revision->id,
            'revision_count' => $number,
            'checkpoint_state_version' => $document->state_version,
            'last_checkpoint_at' => $now,
        ]);

        $this->revisions->prune(
            document: $document,
            max: $this->documentsConfig->revisionLimit(),
            headId: $revision->id,
        );

        return $revision;
    }

    private function intervalElapsed(Document $document): bool
    {
        $interval = $this->documentsConfig->checkpointIntervalSeconds();
        $last = $document->last_checkpoint_at;

        return $interval === 0 || $last === null || $last->lessThanOrEqualTo(Carbon::now()->subSeconds($interval));
    }

    private function node(Document $document): Node
    {
        $node = $document->relationLoaded('node') ? $document->getRelation('node') : $document->node()->first();

        if (!$node instanceof Node) {
            throw new LogicException('[Vaulter] A document aggregate has no node.');
        }

        return $node;
    }

    private function assertTransaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] Document state is written only inside the Vaulter transaction that locked it.');
        }
    }
}
