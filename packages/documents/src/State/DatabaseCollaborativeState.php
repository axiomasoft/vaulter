<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\Actor;

/**
 * {@see CollaborativeState} on the documents' own tables: the locks of the content Actions (drive shared, tree and
 * node, then the v_documents row FOR UPDATE), and every change through DocumentStateWriter.
 *
 * markPending() and publishCheckpoint() read the row again FOR UPDATE — a lock this transaction already holds since
 * lock() — so they decide on the values the write will change, not on the snapshot handed in.
 */
#[Internal]
final readonly class DatabaseCollaborativeState implements CollaborativeState
{
    #[Internal]
    public function __construct(
        private VaulterDatabase $database,
        private NodeReadRepository $nodes,
        private TreeGuard $tree,
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private ContentDecoder $decoder,
        private EventRecorder $recorder,
    ) {}

    public function lock(int|string $nodeId): LockedDocumentState
    {
        $this->assertTransaction();

        $node = $this->nodes->find($nodeId);
        $key = $node?->getRawOriginal($node->getKeyName());
        $driveId = $node?->getRawOriginal('drive_id');
        if (!$node instanceof Node || (!is_int($key) && !is_string($key)) || (!is_int($driveId) && !is_string($driveId))) {
            throw NodeNotFoundException::forId($nodeId);
        }

        $this->database->lockAggregateShared('drives', $driveId);
        $current = $this->tree->locked($this->tree->lock([$key]), $key);
        // A move to another drive between the read and the lock leaves the shared drive lock on the wrong drive.
        if (!$this->tree->sameStorage($current, $driveId)) {
            throw NodeNotFoundException::forId($nodeId);
        }
        if (!$current->kind->isDocument()) {
            throw NotADocumentException::forNode($key);
        }
        $document = $this->documents->lockForNode($current) ?? throw NotADocumentException::forNode($key);

        return new LockedDocumentState(
            documentId: Identity::stringify($document->getKey()),
            nodeId: Identity::stringify($key),
            driveId: Identity::stringify($driveId),
            type: $document->type,
            schemaVersion: $document->schema_version,
            generation: $document->collab_epoch,
            stateVersion: $document->state_version,
            pending: PendingStateGuard::markerOf($document),
            trashed: $current->trashed_at !== null,
        );
    }

    public function markPending(LockedDocumentState $state, int $generation, int $throughSeq): PendingMarker
    {
        $this->assertTransaction();
        if ($throughSeq < 1) {
            throw new InvalidArgumentException('[Vaulter] A pending marker names a log sequence of at least 1.');
        }

        $document = $this->relock($state);
        if ($generation !== $document->collab_epoch) {
            throw new LogicException(sprintf('[Vaulter] A pending marker is set for the current generation %d only, not %d: fence the generation on the locked state first.', $document->collab_epoch, $generation));
        }

        $marker = PendingStateGuard::markerOf($document);
        if ($marker !== null && $marker->generation !== $generation) {
            throw new LogicException(sprintf('[Vaulter] The document still carries a pending marker of generation %d; a generation closes only without one.', $marker->generation));
        }
        if ($marker === null || $marker->throughSeq < $throughSeq) {
            $this->writer->mark($document, $generation, $throughSeq);
        }

        return PendingStateGuard::markerOf($document) ?? throw new LogicException('[Vaulter] The pending marker was not set.');
    }

    public function publishCheckpoint(LockedDocumentState $state, CheckpointPublication $publication): CheckpointOutcome
    {
        $this->assertTransaction();

        $document = $this->relock($state);
        // CAS: a closed generation or a write since the merge started makes the publication lose, untouched.
        if ($publication->generation !== $document->collab_epoch || $publication->expectedStateVersion !== $document->state_version) {
            return new CheckpointOutcome(
                published: false,
                changed: false,
                generation: $document->collab_epoch,
                stateVersion: $document->state_version,
                pending: PendingStateGuard::markerOf($document),
            );
        }

        $node = $document->node()->first();
        if (!$node instanceof Node) {
            throw new LogicException('[Vaulter] A document aggregate has no node.');
        }
        $document->setRelation('node', $node);
        $type = $this->decoder->editableType($document);
        $content = $this->decoder->decode($type, $publication->content);

        $result = $this->writer->write($document, new DocumentStateChange(
            cause: StateWriteCause::Collaboration,
            content: $content,
            schemaVersion: $type->schemaVersion(),
            yjsState: $publication->yjsState,
            actorId: $publication->actor?->id,
        ));

        if ($result->changed) {
            $this->recorder->record(new DocumentContentUpdated(
                node: $node,
                version: $result->checkpoint ?? $document->currentRevision()->firstOrFail(),
                created: $result->checkpoint !== null,
                stateVersion: $document->state_version,
                epoch: $document->collab_epoch,
                actor: $publication->actor ?? Actor::system('collaboration checkpoint')->ref(),
                correlationId: (string) Str::ulid(),
            ));
        }

        // Edits committed after the merge read the log stay pending: only a covering checkpoint clears the marker.
        $marker = PendingStateGuard::markerOf($document);
        if ($marker !== null && $marker->coveredBy($publication->generation, $publication->coveredThrough)) {
            $this->writer->mark($document, null, null);
            $marker = null;
        }

        return new CheckpointOutcome(
            published: true,
            changed: $result->changed,
            generation: $document->collab_epoch,
            stateVersion: $document->state_version,
            pending: $marker,
        );
    }

    public function pendingDocuments(int $limit = 100, int|string|null $after = null): array
    {
        $this->assertTransaction();
        if ($limit < 1) {
            throw new InvalidArgumentException('[Vaulter] A page of pending documents holds at least one.');
        }

        $query = Document::query()->whereNotNull('pending_generation')->orderBy('id')->limit($limit);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return array_values(array_filter(array_map(
            static fn (Document $document): ?PendingMarker => PendingStateGuard::markerOf($document),
            $query->get(['id', 'node_id', 'pending_generation', 'pending_through_seq'])->all(),
        )));
    }

    /** The row lock() took, read again under that lock. */
    private function relock(LockedDocumentState $state): Document
    {
        $document = Document::query()->whereKey($state->documentId)->lockForUpdate()->first();
        if (!$document instanceof Document || Identity::stringify($document->node_id) !== $state->nodeId) {
            throw new LogicException('[Vaulter] The locked document state does not name a document; take it with CollaborativeState::lock() in this transaction.');
        }

        return $document;
    }

    private function assertTransaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] The collaborative state of a document is read and changed only inside a Vaulter transaction.');
        }
    }
}
