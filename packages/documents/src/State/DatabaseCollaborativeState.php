<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
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
use WeakMap;

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
    /** @var WeakMap<LockedDocumentState, array{Connection, DatabaseTransactionRecord}> */
    private WeakMap $issued;

    #[Internal]
    public function __construct(
        private VaulterDatabase $database,
        private Container $container,
        private NodeReadRepository $nodes,
        private TreeGuard $tree,
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private ContentDecoder $decoder,
        private EventRecorder $recorder,
    ) {
        $this->issued = new WeakMap;
    }

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

        $state = new LockedDocumentState(
            documentId: Identity::stringify($document->getKey()),
            nodeId: Identity::stringify($key),
            driveId: Identity::stringify($driveId),
            type: $document->type,
            schemaVersion: $document->schema_version,
            generation: $document->collab_epoch,
            stateVersion: $document->state_version,
            pending: PendingStateGuard::markerOf($document),
            trashed: $current->trashed_at !== null,
            binding: $document->collaborationBinding(),
            collabState: $document->collab_state,
            content: $document->content ?? [],
        );
        $this->issued[$state] = [$this->database->connection(), $this->transactionRecord()];

        return $state;
    }

    public function markPending(LockedDocumentState $state, CollaborationStateBinding $binding, int $throughSeq): PendingMarker
    {
        $this->assertTransaction();
        if ($throughSeq < 1) {
            throw new InvalidArgumentException('[Vaulter] A pending marker names a log sequence of at least 1.');
        }

        $document = $this->relock($state);
        $generation = $binding->generation;
        if (!$binding->sameAs($document->collaborationBinding() ?? throw new LogicException('[Vaulter] No stored binding.'))) {
            throw new LogicException(sprintf('[Vaulter] A pending marker is set for the current generation %d only, not %d: fence the generation on the locked state first.', $document->collab_epoch, $generation));
        }

        $marker = PendingStateGuard::markerOf($document);
        if ($marker !== null && !$marker->binding->sameAs($binding)) {
            throw new LogicException(sprintf('[Vaulter] The document still carries a pending marker of generation %d; a generation closes only without one.', $marker->binding->generation));
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
        $currentBinding = $document->collaborationBinding();
        if ($currentBinding === null || !$publication->binding->sameAs($currentBinding) || $publication->expectedStateVersion !== $document->state_version) {
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
        // A checkpoint drains ACKed history in its stored schema; it is not a current-schema user write.
        $type = $this->decoder->writable($document->type);
        $content = $this->decoder->decode($type, $publication->content, $publication->binding->schemaVersion);

        $result = $this->writer->write($document, new DocumentStateChange(
            cause: StateWriteCause::Collaboration,
            content: $content,
            schemaVersion: $publication->binding->schemaVersion,
            collabState: $publication->collabState,
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
        if ($marker !== null && $marker->coveredBy($publication->binding, $publication->coveredThrough)) {
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

    public function transition(LockedDocumentState $state, CollaborativeStateTransition $transition): CheckpointOutcome
    {
        $this->assertTransaction();
        $document = $this->relock($state);
        $binding = $document->collaborationBinding();
        $sameBinding = $binding === null ? $transition->expectedBinding === null : ($transition->expectedBinding !== null && $binding->sameAs($transition->expectedBinding));
        if (!$sameBinding || $transition->expectedStateVersion !== $document->state_version || PendingStateGuard::markerOf($document) !== null) {
            return new CheckpointOutcome(false, false, $document->collab_epoch, $document->state_version, PendingStateGuard::markerOf($document));
        }
        $type = $this->decoder->writable($transition->documentType);
        if ($type->schemaVersion() !== $transition->schemaVersion
            || ($transition->protocol !== null && (!$type instanceof SupportsCollaboration
                || $type->collaborationProtocol() !== $transition->protocol || $type->collaborationStateFormat() !== $transition->stateFormat))) {
            throw new CollaborationUnavailableException('unsupported_binding', $transition->documentType);
        }
        $content = $this->decoder->decode($type, $transition->content->toArray());
        $node = $document->node()->firstOrFail();
        $document->setRelation('node', $node);
        $result = $this->writer->write($document, new DocumentStateChange(
            cause: StateWriteCause::Transition,
            content: $content,
            schemaVersion: $transition->schemaVersion,
            collabState: $transition->collabState,
            actorId: $transition->actor->id,
            forceCheckpoint: true,
            documentType: $transition->documentType,
            protocol: $transition->protocol,
            stateFormat: $transition->stateFormat,
        ));
        $revision = $result->checkpoint ?? throw new LogicException('[Vaulter] A transition requires a checkpoint.');
        $this->recorder->record(new DocumentRevisionCreated(
            node: $node, version: $revision, stateVersion: $document->state_version, epoch: $document->collab_epoch,
            restored: false, actor: $transition->actor, correlationId: $transition->correlationId,
        ));
        $this->recorder->record(new DocumentContentUpdated(
            node: $node, version: $revision, created: true, stateVersion: $document->state_version,
            epoch: $document->collab_epoch, actor: $transition->actor, correlationId: $transition->correlationId,
        ));

        return new CheckpointOutcome(true, true, $document->collab_epoch, $document->state_version, null);
    }

    public function pendingDocuments(int $limit = 100, int|string|null $after = null, ?string $protocol = null): array
    {
        $this->assertTransaction();
        if ($limit < 1) {
            throw new InvalidArgumentException('[Vaulter] A page of pending documents holds at least one.');
        }

        $query = Document::query()->whereNotNull('pending_generation')->orderBy('id')->limit($limit);
        if ($protocol !== null) {
            $query->where('pending_protocol', $protocol);
        }
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return array_values(array_filter(array_map(
            static fn (Document $document): ?PendingMarker => PendingStateGuard::markerOf($document),
            $query->get(['id', 'node_id', 'pending_generation', 'pending_through_seq', 'pending_protocol', 'pending_state_format', 'pending_schema_version'])->all(),
        )));
    }

    /** The row lock() took, read again under that lock. */
    private function relock(LockedDocumentState $state): Document
    {
        $issued = $this->issued[$state] ?? null;
        if ($issued === null || $issued[0] !== $this->database->connection() || $issued[1] !== $this->transactionRecord()) {
            throw new LogicException('[Vaulter] Use the exact state object issued by lock() in this transaction attempt.');
        }
        $document = Document::query()->whereKey($state->documentId)->lockForUpdate()->first();
        if (!$document instanceof Document || Identity::stringify($document->node_id) !== $state->nodeId) {
            throw new LogicException('[Vaulter] The locked document state does not name a document; take it with CollaborativeState::lock() in this transaction.');
        }

        return $document;
    }

    private function transactionRecord(): DatabaseTransactionRecord
    {
        $connection = $this->database->connection();
        $manager = $this->container->make('db.transactions');
        $record = $manager->getPendingTransactions()->last(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel(),
        );

        return $record ?? throw new LogicException('[Vaulter] No live transaction attempt owns this state lock.');
    }

    private function assertTransaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] The collaborative state of a document is read and changed only inside a Vaulter transaction.');
        }
    }
}
