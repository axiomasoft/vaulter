<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Pagination\CursorPaginator;
use LogicException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Page;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Access\CollaborationAccessPolicy;
use Vaulter\Documents\Actions\Documents\ChangeDocumentWriteModeAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\DocumentWriteLock;
use Vaulter\Documents\Actions\Documents\InitializeBlockIdsAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\ManagedDocumentCodec;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Idempotency\DocumentRevisionSnapshotCodec;
use Vaulter\Documents\Idempotency\DocumentSnapshotCodec;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\Queries\MappedCursorPaginator;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

/**
 * The public PHP API of documents in one drive: `Documents::in($drive)`.
 *
 * Every method first locates the node inside the handle's drive (a trashed node, a
 * node of another drive or tenant, or one the actor cannot see is "not found") and
 * asks the OperationGuard for the ability it needs, so the profile, tenancy and
 * content-egress rules apply the same way they do for files. Reading content needs
 * View; changing it needs Edit; taking it out as an export needs Download.
 *
 * Content writes use the document's own validator: pass DocumentSnapshot::$stateVersion
 * back as $expectedStateVersion to refuse a lost update (412 document_state_mismatch).
 * The node's sequence is not involved, so renaming a document never invalidates it.
 *
 * Content is shaped by the document's type (Documents::types()). A document whose type
 * is not installed can still be read (as opaque JSON), listed, copied, trashed and
 * purged; editing, checkpointing, restoring and exporting answer 409
 * document_type_unavailable until the type is registered again. Content that reads only partly under its type
 * (DocumentSnapshot::$editable = false) is not overwritten: 409 document_content_degraded.
 *
 * Every write but the autosave is idempotent by key: the receipt is the created node, the document state or the
 * checkpoint the first request answered. The HTTP autosave (updateContent) is conditional instead: its repeat after the
 * commit carries a stale state version and answers 412 without a second revision.
 *
 * A document with saved collaboration edits its working state does not hold yet (the pending-state
 * marker) is first drained through the collaboration transport; while the marker stays, its
 * working state is neither read, exported nor replaced or restored: 409 document_state_pending.
 */
#[Api]
final readonly class DocumentOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ProfilePolicy $profiles,
        private Container $container,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
        private CreateDocumentAction $create,
        private DocumentWriteLock $writeLock,
        private UpdateDocumentContentAction $update,
        private InitializeBlockIdsAction $initializeBlockIds,
        private ChangeDocumentWriteModeAction $changeWriteMode,
        private CreateNamedRevisionAction $snapshot,
        private RestoreDocumentRevisionAction $restore,
        private UpdateRevisionRetentionAction $retention,
        private ExportDocumentAction $export,
        private DocumentQuery $query,
        private DocumentSnapshotMapper $mapper,
        private NodeSnapshotMapper $nodes,
        private OperationRunner $runner,
        private VaulterDatabase $database,
        private PendingStateGuard $pending,
        private CollaborationAccessPolicy $collaborationPolicy,
        private Kernel $kernel,
        private MetricsRecorder $metrics,
    ) {}

    /** Issue a protocol-neutral session after the shared live access policy. */
    public function collaborationSession(NodeSnapshot|string|int $document, ?int $clientSchemaVersion = null): CollaborationSession
    {
        [$decision, $node, $aggregate] = $this->collaborationPolicy->evaluateForSession($this->context, $document, $clientSchemaVersion);
        if ($decision->grant->mode === CollaborationMode::Write) {
            $this->assertSessionSeesCommittedState();
            if ($aggregate->write_mode === DocumentWriteMode::Snapshot) {
                $decision = $this->write('change_write_mode', fn () => $this->changeWriteMode->execute(
                    $this->context, $node, $aggregate->collab_epoch, $aggregate->state_version, $clientSchemaVersion,
                ));
            }
        }
        $grant = $decision->grant;
        $driver = $this->kernel->extensions()->handler(CollaborationProtocolDriver::class, $grant->protocol);
        if ($driver->protocol() !== $grant->protocol) {
            throw new CollaborationProtocolContractException;
        }
        $session = $driver->issueSession($grant);
        $session->assertGrant($grant, $driver->version());
        $this->metrics->counter(name: 'vaulter_collab_session_issued_total', labels: ['protocol' => $grant->protocol, 'ability' => $decision->ability->value]);

        return $session;
    }

    private function assertSessionSeesCommittedState(): void
    {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() === 0) {
            return;
        }

        /** @var DatabaseTransactionsManager $transactions */
        $transactions = $this->container->make('db.transactions');
        if ($transactions->callbackApplicableTransactions()->contains(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName(),
        )) {
            throw new LogicException('[Vaulter] A writable collaboration session requires committed document state.');
        }
    }

    /**
     * Create a document of $type (vaulter-documents.default_type when null) under
     * $parent (the drive root when null).
     *
     * @param array<string, mixed>|null $content the initial content; null starts with the type's default
     *
     * @throws UnknownDocumentTypeException    when no registered type has the key
     * @throws InvalidDocumentContentException when the type's codec rejects the content
     */
    public function create(string $name, ?string $type = null, ?array $content = null, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot
    {
        $parentId = $parent === null ? null : self::idOf($parent);
        // A replay needs Create in the folder the document is in now.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forCreate($this->context, $this->locator, $this->guard, $parentId),
            fingerprint: ['name' => $name, 'type' => $type, 'content' => $content, 'parent' => $parentId, 'on_conflict' => ($options ?? new CreateFolderOptions)->onConflict->value],
        );

        $prepared = null;
        if ($content !== null) {
            $candidateType = $this->types->find($type ?? $this->container->make(DocumentsOptions::class)->defaultType);
            $codec = $candidateType?->codec();
            if ($codec instanceof ManagedDocumentCodec) {
                $folder = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
                $this->guard->authorizeCreate($this->context, $folder);
                $this->profiles->assertDocumentType(drive: $this->context->drive, type: $candidateType->key());
                $prepared = $codec->prepare($this->decoder->decode($candidateType, $content), $this->context);
            }
        }

        /** @var NodeSnapshot $created */
        $created = $this->write('create', function () use ($name, $type, $content, $parent, $options, $prepared): NodeSnapshot {
            $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
            $this->guard->authorizeCreate($this->context, $parent);
            $documentType = $this->types->get($type ?? $this->container->make(DocumentsOptions::class)->defaultType);
            $this->profiles->assertDocumentType(drive: $this->context->drive, type: $documentType->key());

            $node = $this->create->execute($this->context, new CreateDocumentInput(
                storage: $this->context->drive,
                name: $name,
                content: $prepared ?? $this->decoder->decode($documentType, $content),
                parent: $parent,
                type: $documentType->key(),
                schemaVersion: $documentType->schemaVersion(),
                onConflict: ($options ?? new CreateFolderOptions)->onConflict,
            ));

            return $this->nodes->map($node, $this->context->actor->ref());
        }, $idempotency);

        // The attempt carries the reader key; a replayed receipt (semantic, keyless) gets it once the replay is authorized.
        return $created->syncKey === null ? $this->nodes->withSyncKey($created, $this->context->actor->ref()) : $created;
    }

    /**
     * The working state, or the content of one checkpoint when $revision is given.
     *
     * @throws DocumentStatePendingException when the working state is pending and could not be drained
     */
    public function content(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int|null $revision = null): DocumentSnapshot
    {
        return $this->pending->settled(function () use ($document, $revision): DocumentSnapshot {
            // Inline content has no scan evidence, but it leaves through the same egress
            // gate as file bytes so profile and tenant rules apply to it too.
            $node = $this->document($document, Ability::View, OperationKind::ContentEgress);
            $aggregate = $this->query->document($node, withState: true);
            if ($revision === null) {
                $this->pending->assertSettled($aggregate);
            }
            $selected = $revision === null ? null : $this->query->revision($node, $aggregate, $revision);

            return $this->mapper->document($aggregate, $aggregate->currentRevision, $selected);
        });
    }

    /**
     * Replace the working state. A checkpoint is taken on the configured interval, not
     * on every write.
     *
     * @param array<string, mixed> $content
     *
     * @throws DocumentTypeUnavailableException when the document's type is not installed
     * @throws DocumentContentDegradedException when the stored content reads only partly under its type
     * @throws DocumentStatePendingException    when the working state is pending and could not be drained
     */
    public function update(NodeSnapshot|string|int $document, array $content, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        $nodeId = self::idOf($document);
        $idempotency = new IdempotentWrite(
            codec: new DocumentSnapshotCodec($this->decoder),
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $nodeId, requireExistingNode: true),
            fingerprint: ['node' => $nodeId, 'content' => $content, 'expected_state_version' => $expectedStateVersion],
        );

        return $this->pending->settled(fn (): DocumentSnapshot => $this->write('update', fn (): DocumentSnapshot => $this->writeContent($document, $content, $expectedStateVersion), $idempotency));
    }

    /**
     * The HTTP JSON autosave; binary state is written only through CollaborativeState.
     *
     * @param array<string, mixed>         $content
     * @param (Closure(string): bool)|null $matchesTag
     */
    #[Internal]
    public function updateContent(NodeSnapshot|string|int $document, array $content, ?int $expectedStateVersion = null, ?Closure $matchesTag = null): DocumentSnapshot
    {
        return $this->pending->settled(fn (): DocumentSnapshot => $this->write('update_content', fn (): DocumentSnapshot => $this->writeContent($document, $content, $expectedStateVersion, $matchesTag)));
    }

    /**
     * @param array<string, mixed>         $content
     * @param (Closure(string): bool)|null $matchesTag
     */
    private function writeContent(NodeSnapshot|string|int $document, array $content, ?int $expectedStateVersion, ?Closure $matchesTag = null): DocumentSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
        $result = $this->update->execute($this->context, new UpdateDocumentContentInput(
            node: $node,
            content: $content,
            expectedStateVersion: $expectedStateVersion,
            matchesTag: $matchesTag,
        ));

        return $this->mapper->document($result->document, $result->document->currentRevision);
    }

    /**
     * Authorized CAS normalization of a rich-text document to schema v2 with unique block IDs.
     * Repeating normalization on an already-normalized document is a no-op.
     */
    public function initializeBlockIds(NodeSnapshot|string|int $document, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        return $this->pending->settled(fn (): DocumentSnapshot => $this->write('initialize_block_ids', function () use ($document, $expectedStateVersion): DocumentSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $result = $this->initializeBlockIds->execute($this->context, $node, $expectedStateVersion);

            return $this->mapper->document($result->document, $result->document->currentRevision);
        }));
    }

    /** Checkpoint the working state as a named revision; it becomes the head. */
    public function snapshot(NodeSnapshot|string|int $document, ?string $label = null, bool $keepForever = false): DocumentRevisionSnapshot
    {
        $nodeId = self::idOf($document);
        $idempotency = new IdempotentWrite(
            codec: new DocumentRevisionSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $nodeId, requireExistingNode: true),
            fingerprint: ['node' => $nodeId, 'label' => $label, 'keep_forever' => $keepForever],
        );

        return $this->write('snapshot', function () use ($document, $label, $keepForever): DocumentRevisionSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $this->installedType($node);
            $revision = $this->snapshot->execute($this->context, new CreateNamedRevisionInput(node: $node, label: $label, keepForever: $keepForever));

            return $this->mapper->revision($revision, $this->query->document($node));
        }, $idempotency);
    }

    /** @return Page<DocumentRevisionSnapshot> newest first */
    public function revisions(NodeSnapshot|string|int $document, ?ListOptions $options = null): Page
    {
        $page = $this->revisionPage($document, $options);

        return new Page(items: array_values($page->items()), nextCursor: $page->nextCursor()?->encode());
    }

    /**
     * The HTTP history listing keeps its cursor-paginator JSON until REST v1.
     *
     * @return CursorPaginator<int, DocumentRevisionSnapshot>
     */
    #[Internal]
    public function revisionPage(NodeSnapshot|string|int $document, ?ListOptions $options = null): CursorPaginator
    {
        $node = $this->document($document, Ability::View, OperationKind::Metadata);
        $aggregate = $this->query->document($node);

        $source = $this->query->revisions($aggregate, $options ?? new ListOptions);

        /** @var MappedCursorPaginator<int, DocumentRevisionSnapshot> $page */
        $page = new MappedCursorPaginator(
            $source,
            array_values(array_map(fn (DocumentRevision $revision): DocumentRevisionSnapshot => $this->mapper->revision($revision, $aggregate), $source->items())),
            $source->nextCursor(),
            $source->previousCursor(),
        );

        return $page;
    }

    /**
     * Copy a checkpoint forward into the working state. The checkpoint itself is never
     * changed; the restore is recorded as a new head checkpoint and open collaboration
     * rooms move to a new epoch.
     *
     * @throws DocumentStatePendingException when the working state is pending and could not be drained
     */
    public function restore(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        $nodeId = self::idOf($document);
        $idempotency = new IdempotentWrite(
            codec: new DocumentSnapshotCodec($this->decoder),
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $nodeId, requireExistingNode: true),
            fingerprint: ['node' => $nodeId, 'revision' => $revision instanceof DocumentRevisionSnapshot ? $revision->id : (string) $revision, 'expected_state_version' => $expectedStateVersion],
        );

        return $this->pending->settled(fn (): DocumentSnapshot => $this->write('restore', function () use ($document, $revision, $expectedStateVersion): DocumentSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $this->installedType($node);
            $this->restore->execute($this->context, new RestoreDocumentRevisionInput(
                node: $node,
                revision: $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision,
                expectedStateVersion: $expectedStateVersion,
            ));

            return $this->current($node);
        }, $idempotency));
    }

    /** Pin a checkpoint out of pruning, or release the pin. */
    public function setRetention(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision, bool $keepForever): DocumentRevisionSnapshot
    {
        return $this->write('set_retention', function () use ($document, $revision, $keepForever): DocumentRevisionSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $changed = $this->retention->execute($this->context, new UpdateRevisionRetentionInput(
                node: $node,
                revision: $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision,
                keepForever: $keepForever,
            ));

            return $this->mapper->revision($changed, $this->query->document($node));
        });
    }

    /**
     * Hold the document write lock for a dependent write until the current Vaulter transaction completes.
     * Call this before taking related row locks and authorize the dependent write in its owning module.
     */
    public function lockForRelatedWrite(NodeSnapshot|string|int $document): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] Locking a document for a related write requires an active transaction.');
        }

        $node = $this->document($document, Ability::View, OperationKind::Mutation);
        $current = $this->writeLock->lockNode($this->context, $node->id);
        $this->guard->authorize($this->context, Ability::View, $current, OperationKind::Mutation);
        $this->writeLock->lockDocument($current);
    }

    /**
     * Render the working state (or one checkpoint) with the exporter registered for
     * $format and the document's type. Needs Download: an export is a copy of the
     * content, which View alone does not allow. Never creates a revision.
     *
     * @throws DocumentTypeUnavailableException when the document's type is not installed
     * @throws UnknownExportFormatException     when the type has no exporter for $format (422, with the allowed formats)
     * @throws DocumentStatePendingException    when the working state is pending and could not be drained
     */
    public function export(NodeSnapshot|string|int $document, string $format, DocumentRevisionSnapshot|string|int|null $revision = null): DocumentExport
    {
        return $this->pending->settled(function () use ($document, $format, $revision): DocumentExport {
            $node = $this->document($document, Ability::Download, OperationKind::ContentEgress);
            $this->installedType($node);
            $selected = $revision === null ? null : $this->query->revision($node, $this->query->document($node), $revision);

            return $this->export->execute($this->context, new ExportDocumentInput(node: $node, format: $format, revision: $selected));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, ?IdempotentWrite $idempotency = null): mixed
    {
        return $this->runner->write(new OperationSpec('documents.documents.'.$verb, new ModuleId('documents')), $this->context->operation, $attempt, $idempotency);
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private function document(NodeSnapshot|string|int $key, Ability $ability, OperationKind $operation): Node
    {
        $node = $this->locator->find($this->context, $key);
        // Authorize before the kind check, so a node the actor cannot see stays
        // indistinguishable from a missing one.
        $this->guard->authorize($this->context, $ability, $node, $operation);
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        return $node;
    }

    private function current(Node $node): DocumentSnapshot
    {
        $aggregate = $this->query->document($node, withState: true);

        return $this->mapper->document($aggregate, $aggregate->currentRevision);
    }

    /** @throws DocumentTypeUnavailableException */
    private function installedType(Node $node): DocumentType
    {
        return $this->decoder->writable($this->query->document($node)->type);
    }
}
