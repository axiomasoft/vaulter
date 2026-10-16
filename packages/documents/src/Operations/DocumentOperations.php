<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Closure;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\DriveExtension;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Collaboration\CollaborationSessionService;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
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
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\Queries\MappedCursorPaginator;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Profiles\ProfilePolicy;

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
 * document_type_unavailable until the type is registered again, and its collaboration
 * room is read-only. Content that reads only partly under its type
 * (DocumentSnapshot::$editable = false) is not overwritten: 409 document_content_degraded.
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
        private DocumentsConfig $config,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
        private CreateDocumentAction $create,
        private UpdateDocumentContentAction $update,
        private CreateNamedRevisionAction $snapshot,
        private RestoreDocumentRevisionAction $restore,
        private UpdateRevisionRetentionAction $retention,
        private ExportDocumentAction $export,
        private CollaborationSessionService $sessions,
        private DocumentQuery $query,
        private DocumentSnapshotMapper $mapper,
        private NodeSnapshotMapper $nodes,
        private OperationRunner $runner,
    ) {}

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
        return $this->write('create', function () use ($name, $type, $content, $parent, $options): NodeSnapshot {
            $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
            $this->guard->authorizeCreate($this->context, $parent);
            $documentType = $this->types->get($type ?? $this->config->defaultType());
            $this->profiles->assertDocumentType(drive: $this->context->drive, type: $documentType->key());

            $node = $this->create->execute($this->context, new CreateDocumentInput(
                storage: $this->context->drive,
                name: $name,
                content: $this->decoder->decode($documentType, $content),
                parent: $parent,
                type: $documentType->key(),
                schemaVersion: $documentType->schemaVersion(),
                onConflict: ($options ?? new CreateFolderOptions)->onConflict,
            ));

            return $this->nodes->map($node, $this->context->actor->ref());
        });
    }

    /** The working state, or the content of one checkpoint when $revision is given. */
    public function content(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int|null $revision = null): DocumentSnapshot
    {
        // Inline content has no scan evidence, but it leaves through the same egress
        // gate as file bytes so profile and tenant rules apply to it too.
        $node = $this->document($document, Ability::View, OperationKind::ContentEgress);
        $aggregate = $this->query->document($node);
        $selected = $revision === null ? null : $this->query->revision($node, $aggregate, $revision);

        return $this->mapper->document($aggregate, $aggregate->currentRevision, $selected);
    }

    /**
     * Replace the working state. A checkpoint is taken on the configured interval, not
     * on every write.
     *
     * @param array<string, mixed> $content
     *
     * @throws DocumentTypeUnavailableException when the document's type is not installed
     * @throws DocumentContentDegradedException when the stored content reads only partly under its type
     */
    public function update(NodeSnapshot|string|int $document, array $content, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        return $this->write('update', fn (): DocumentSnapshot => $this->writeContent($document, $content, null, $expectedStateVersion));
    }

    /**
     * The HTTP autosave, which may also carry the binary Yjs state.
     *
     * @param array<string, mixed> $content
     */
    #[Internal]
    public function updateContent(NodeSnapshot|string|int $document, array $content, ?string $yjsState = null, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        return $this->write('update_content', fn (): DocumentSnapshot => $this->writeContent($document, $content, $yjsState, $expectedStateVersion));
    }

    /** @param array<string, mixed> $content */
    private function writeContent(NodeSnapshot|string|int $document, array $content, ?string $yjsState, ?int $expectedStateVersion): DocumentSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
        // Checked here so a degraded document answers before its payload is validated,
        // and again on the locked row, where a racing restore can no longer slip in.
        $type = $this->decoder->editableType($this->query->document($node));

        $this->update->execute($this->context, new UpdateDocumentContentInput(
            node: $node,
            content: $this->decoder->decode($type, $content),
            yjsState: $yjsState,
            expectedStateVersion: $expectedStateVersion,
        ));

        return $this->current($node);
    }

    /** Checkpoint the working state as a named revision; it becomes the head. */
    public function snapshot(NodeSnapshot|string|int $document, ?string $label = null, bool $keepForever = false): DocumentRevisionSnapshot
    {
        return $this->write('snapshot', function () use ($document, $label, $keepForever): DocumentRevisionSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $this->installedType($node);
            $revision = $this->snapshot->execute($this->context, new CreateNamedRevisionInput(node: $node, label: $label, keepForever: $keepForever));

            return $this->mapper->revision($revision, $this->query->document($node));
        });
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
     */
    public function restore(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        return $this->write('restore', function () use ($document, $revision, $expectedStateVersion): DocumentSnapshot {
            $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
            $this->installedType($node);
            $this->restore->execute($this->context, new RestoreDocumentRevisionInput(
                node: $node,
                revision: $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision,
                expectedStateVersion: $expectedStateVersion,
            ));

            return $this->current($node);
        });
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
     * Render the working state (or one checkpoint) with the exporter registered for
     * $format and the document's type. Needs Download: an export is a copy of the
     * content, which View alone does not allow. Never creates a revision.
     *
     * @throws DocumentTypeUnavailableException when the document's type is not installed
     * @throws UnknownExportFormatException     when the type has no exporter for $format (422, with the allowed formats)
     */
    public function export(NodeSnapshot|string|int $document, string $format, DocumentRevisionSnapshot|string|int|null $revision = null): DocumentExport
    {
        $node = $this->document($document, Ability::Download, OperationKind::ContentEgress);
        $this->installedType($node);
        $selected = $revision === null ? null : $this->query->revision($node, $this->query->document($node), $revision);

        return $this->export->execute($this->context, new ExportDocumentInput(node: $node, format: $format, revision: $selected));
    }

    /**
     * A token for the collaboration hub, bound to the document's current generation
     * (epoch): read-write when the actor may edit, read-only otherwise. Needs a user:
     * the hub attributes every change to the token subject. Pass the schema version
     * the client's editor speaks; a different one gets a read-only room.
     *
     * @throws CollaborationUnavailableException when collaboration is off or the type cannot collaborate
     */
    public function collaborationSession(NodeSnapshot|string|int $document, ?int $clientSchemaVersion = null): CollaborationSession
    {
        $node = $this->document($document, Ability::View, OperationKind::Metadata);
        $actor = $this->context->actor->ref()->id;
        if ($this->context->actor->isSystem() || $actor === null) {
            throw MissingActorException::required();
        }
        if (!$this->config->collaborationEnabled()) {
            throw CollaborationUnavailableException::disabled();
        }
        $aggregate = $this->query->document($node);
        $type = $this->types->find($aggregate->type);
        if ($type !== null && !$type instanceof SupportsCollaboration) {
            throw CollaborationUnavailableException::unsupportedType($type->key());
        }
        $flags = $this->guard->abilities($this->context, $node)->flags;
        $ability = match (true) {
            $flags[Ability::Edit->value] ?? false => Ability::Edit,
            $flags[Ability::Comment->value] ?? false => Ability::Comment,
            default => Ability::View,
        };
        // The same rule the hub re-checks on every call: only an editor of content this
        // installation can save back, on an active drive, with a client that speaks the
        // type's schema, gets a room that writes; everyone else watches it.
        $writable = $ability === Ability::Edit
            && $type !== null
            && $this->decoder->editable($aggregate)
            && ($clientSchemaVersion === null || $clientSchemaVersion === $type->schemaVersion())
            && $node->storage()->value('status') === DriveStatus::Active;

        return $this->sessions->issue(
            node: $node,
            ability: $ability,
            epoch: $aggregate->collab_epoch,
            schemaVersion: $type?->schemaVersion() ?? $aggregate->schema_version,
            writable: $writable,
            actorId: $actor,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt): mixed
    {
        return $this->runner->write(new OperationSpec('documents.documents.'.$verb, new ModuleId('documents')), $this->context->operation, $attempt);
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
        $aggregate = $this->query->document($node);

        return $this->mapper->document($aggregate, $aggregate->currentRevision);
    }

    /** @throws DocumentTypeUnavailableException */
    private function installedType(Node $node): DocumentType
    {
        return $this->decoder->writable($this->query->document($node)->type);
    }
}
