<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Documents\DocumentStoreRepository;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\Int64Counter;

/**
 * The only writer of a document's working state and its checkpoints. Callers are
 * Actions that already hold, inside one Vaulter transaction, the drive tree lock,
 * the node lock and the v_documents row FOR UPDATE (T → N → D), so every check
 * here is made against the row the write will change.
 *
 * Content has its own validators: state_version moves by exactly one per effective
 * write and collab_epoch whenever open collaboration rooms must not write back
 * (restore or a generation transition). v_nodes.sequence is never touched — renaming a
 * document and editing it do not invalidate each other.
 */
#[Internal]
final readonly class DocumentStateWriter
{
    #[Internal]
    public function __construct(
        private DocumentStoreRepository $documents,
        private DocumentRevisionStoreRepository $revisions,
        private NodeStoreRepository $nodes,
        private Container $container,
        private VaulterDatabase $database,
        private ContentDecoder $decoder,
    ) {}

    public function write(Document $locked, DocumentStateChange $change): DocumentStateResult
    {
        $this->assertTransaction();

        $this->assertExpectedVersion($locked, $change->expectedStateVersion);

        $checkpointDue = $this->intervalElapsed($locked);
        $payload = $locked->payload;
        $sync = $locked->syncState;
        $hash = $change->content?->contentHash() ?? $payload?->content_hash;
        // A bound snapshot save reseeds the working state, even when the JSON is identical.
        if ($change->cause->skipsIdenticalWrite()
            && ($change->cause !== StateWriteCause::Update || $sync?->collab_protocol === null)
            && $hash === $payload?->content_hash
            && $change->collabState === $sync?->collab_state) {
            return new DocumentStateResult(changed: false, document: $locked, checkpoint: null, epochChanged: false);
        }

        $epochChanged = $change->cause === StateWriteCause::Restore || $change->cause === StateWriteCause::Transition;

        // Preserve a dirty binary checkpoint before replacing it or closing its generation.
        $replacesCheckpoint = $change->cause === StateWriteCause::Update && $sync?->collab_state !== null;
        if (($epochChanged || $replacesCheckpoint) && $locked->hasUncheckpointedChanges()) {
            $this->capture(
                document: $locked,
                label: null,
                keepForever: false,
                autosave: true,
                restoredFrom: null,
                actorId: $change->actorId,
            );
        }

        $attributes = [
            'state_version' => Int64Counter::increment($locked->state_version),
            'collab_epoch' => $epochChanged ? Int64Counter::increment($locked->collab_epoch) : $locked->collab_epoch,
            'schema_version' => $change->schemaVersion,
            'updated_by' => $change->actorId,
        ];

        $protocol = $sync?->collab_protocol;
        $format = $sync?->collab_state_format;
        if ($change->cause === StateWriteCause::Create) {
            $type = $this->decoder->writable($locked->type);
            if ($type instanceof SupportsCollaboration) {
                $protocol = $type->collaborationProtocol();
                $format = $type->collaborationStateFormat();
            }
        }
        if (in_array($change->cause, [StateWriteCause::Transition, StateWriteCause::Restore, StateWriteCause::Copy], true)) {
            $attributes['type'] = $change->documentType ?? $locked->type;
            $protocol = $change->protocol;
            $format = $change->stateFormat;
        }
        if ($change->writeMode !== null) {
            $attributes['write_mode'] = $change->writeMode;
        }
        $this->documents->update($locked, $attributes);

        if ($change->content !== null || $payload === null || $change->cause === StateWriteCause::Restore) {
            $this->documents->savePayload($locked, [
                'content' => $change->content?->toArray(),
                'content_hash' => $change->content?->contentHash(),
                'content_size' => $change->content?->sizeInBytes(),
            ]);
        }
        $this->documents->saveSyncState($locked, [
            'collab_protocol' => $protocol,
            'collab_state_format' => $format,
            'collab_state' => $change->collabState,
            'collab_state_size' => $change->collabState === null ? null : strlen($change->collabState),
        ]);

        $checkpoint = null;
        if ($change->forceCheckpoint || $locked->current_revision_id === null || $checkpointDue) {
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

    /**
     * Set the pending-state marker — the generation and the last committed collaboration sequence the working
     * state does not cover yet — or clear it with two nulls. The validators and the working state stay as they are.
     */
    public function mark(Document $locked, ?int $generation, ?int $throughSeq): void
    {
        $this->assertTransaction();

        if (($generation === null) !== ($throughSeq === null) || ($generation ?? 0) < 0 || ($throughSeq ?? 1) < 1) {
            throw new InvalidArgumentException('[Vaulter] A pending marker is a generation of at least 0 with a sequence of at least 1, or none at all.');
        }

        $sync = $locked->syncState;
        $this->documents->saveSyncState($locked, [
            'pending_protocol' => $generation === null ? null : $sync?->collab_protocol,
            'pending_state_format' => $generation === null ? null : $sync?->collab_state_format,
            'pending_schema_version' => $generation === null ? null : $locked->schema_version,
            'pending_generation' => $generation,
            'pending_through_seq' => $throughSeq,
        ]);
    }

    private function assertExpectedVersion(Document $document, ?int $expected): void
    {
        if ($expected === null) {
            return;
        }
        if ($expected < 0) {
            throw new InvalidArgumentException('[Vaulter] An expected state version must be nonnegative.');
        }
        if ($expected !== $document->state_version) {
            throw new StaleDocumentStateException(expected: $expected, actual: $document->state_version, nodeId: Identity::stringify($document->node_id));
        }
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

        $payload = $document->payload;
        $sync = $document->syncState;
        $revision = $this->revisions->create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->getKey(),
            'number' => $number,
            'type' => $document->type,
            'collab_epoch' => $document->collab_epoch,
            'schema_version' => $document->schema_version,
            'state_version' => $document->state_version,
            'label' => $label,
            'is_autosave' => $autosave,
            'keep_forever' => $keepForever,
            'status' => 'committed',
            'restored_from_revision_id' => $restoredFrom?->getKey(),
            'created_by' => $actorId,
            'created_at' => $now,
        ], [
            'content' => $payload?->content,
            'content_hash' => $payload?->content_hash,
            'content_size' => $payload?->content_size,
        ], $sync?->collab_protocol === null ? null : [
            'collab_protocol' => $sync->collab_protocol,
            'collab_state_format' => $sync->collab_state_format,
            'collab_state' => $sync->collab_state,
            'collab_state_size' => $sync->collab_state_size,
        ]);

        $this->documents->update($document, [
            'current_revision_id' => $revision->id,
            'revision_count' => $number,
            'checkpoint_state_version' => $document->state_version,
            'last_checkpoint_at' => $now,
        ]);

        $this->revisions->prune(
            document: $document,
            max: $this->container->make(DocumentsOptions::class)->revisions->limit,
            headId: $revision->id,
        );

        return $revision;
    }

    private function intervalElapsed(Document $document): bool
    {
        $interval = $this->container->make(DocumentsOptions::class)->revisions->checkpointIntervalSeconds;
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
