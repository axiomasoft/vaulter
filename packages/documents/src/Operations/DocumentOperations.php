<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Collaboration\CollaborationSessionService;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\Queries\MappedCursorPaginator;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Handlers\FileDescriptorFactory;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
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
 */
final readonly class DocumentOperations
{
    private const string SPREADSHEET_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private OperationContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ProfilePolicy $profiles,
        private DocumentsConfig $config,
        private CreateDocumentAction $create,
        private UpdateDocumentContentAction $update,
        private CreateNamedRevisionAction $snapshot,
        private RestoreDocumentRevisionAction $restore,
        private UpdateRevisionRetentionAction $retention,
        private NodeExportService $exports,
        private CollaborationSessionService $sessions,
        private DocumentQuery $query,
        private DocumentSnapshotMapper $mapper,
        private NodeSnapshotMapper $nodes,
        private FileDescriptorFactory $descriptors,
        private FileHandlerRegistry $handlers,
    ) {}

    /**
     * Create a document under $parent (the drive root when null).
     *
     * @param array<string, mixed>|null $content the initial content; null starts empty
     *
     * @throws UnknownDocumentTypeException when the type is unknown or not enabled
     */
    public function create(string $name, string $type = 'vaulter/rich-text', ?array $content = null, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot
    {
        [$format, $mimeType, $richText] = $this->typeShape($type);
        $payload = $richText
            ? ($content === null ? TipTapContent::empty() : TipTapContent::fromArray($content))
            : OpaqueDocumentContent::fromArray($content ?? []);

        return $this->createDocument($name, $format, $mimeType, $payload, $parent, $options);
    }

    /**
     * @internal The HTTP create, whose request already resolved the format label and the
     * handler mime from its own fields.
     */
    public function createDocument(string $name, string $format, string $mimeType, DocumentContent $content, NodeSnapshot|string|int|null $parent = null, ?CreateFolderOptions $options = null): NodeSnapshot
    {
        $parent = $this->locator->find($this->context, $parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeCreate($this->context, $parent);
        $this->profiles->assertDocumentType(drive: $this->context->drive, type: $format);

        $node = $this->create->execute($this->context, new CreateDocumentInput(
            storage: $this->context->drive,
            name: $name,
            content: $content,
            parent: $parent,
            format: $format,
            mimeType: $mimeType,
            onConflict: ($options ?? new CreateFolderOptions)->onConflict,
        ));

        return $this->nodes->map($node, $this->context->actor->ref());
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
     */
    public function update(NodeSnapshot|string|int $document, array $content, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);

        return $this->write($node, $this->contentFor($node, $content), null, $expectedStateVersion);
    }

    /**
     * @internal The HTTP autosave, whose request already shaped the content for the
     * node's handler and may carry the binary Yjs state.
     */
    public function updateContent(NodeSnapshot|string|int $document, DocumentContent $content, ?string $yjsState = null, ?int $expectedStateVersion = null): DocumentSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);

        return $this->write($node, $content, $yjsState, $expectedStateVersion);
    }

    /** Checkpoint the working state as a named revision; it becomes the head. */
    public function snapshot(NodeSnapshot|string|int $document, ?string $label = null, bool $keepForever = false): DocumentRevisionSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
        $revision = $this->snapshot->execute($this->context, new CreateNamedRevisionInput(node: $node, label: $label, keepForever: $keepForever));

        return $this->mapper->revision($revision, $this->query->document($node));
    }

    /** @return Page<DocumentRevisionSnapshot> newest first */
    public function revisions(NodeSnapshot|string|int $document, ?ListOptions $options = null): Page
    {
        $page = $this->revisionPage($document, $options);

        return new Page(items: array_values($page->items()), nextCursor: $page->nextCursor()?->encode());
    }

    /**
     * @internal The HTTP history listing keeps its cursor-paginator JSON until REST v1.
     *
     * @return CursorPaginator<int, DocumentRevisionSnapshot>
     */
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
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
        $this->restore->execute($this->context, new RestoreDocumentRevisionInput(
            node: $node,
            revision: $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision,
            expectedStateVersion: $expectedStateVersion,
        ));

        return $this->current($node);
    }

    /** Pin a checkpoint out of pruning, or release the pin. */
    public function setRetention(NodeSnapshot|string|int $document, DocumentRevisionSnapshot|string|int $revision, bool $keepForever): DocumentRevisionSnapshot
    {
        $node = $this->document($document, Ability::Edit, OperationKind::Mutation);
        $changed = $this->retention->execute($this->context, new UpdateRevisionRetentionInput(
            node: $node,
            revision: $revision instanceof DocumentRevisionSnapshot ? $revision->id : $revision,
            keepForever: $keepForever,
        ));

        return $this->mapper->revision($changed, $this->query->document($node));
    }

    /**
     * Render the working state (or one checkpoint) with the exporter registered for
     * $format. Needs Download: an export is a copy of the content, which View alone
     * does not allow.
     */
    public function export(NodeSnapshot|string|int $document, string $format, DocumentRevisionSnapshot|string|int|null $revision = null): DocumentExport
    {
        $node = $this->document($document, Ability::Download, OperationKind::ContentEgress);
        $selected = $revision === null ? null : $this->query->revision($node, $this->query->document($node), $revision);

        return $this->exports->export($this->context, $node, $format, $selected);
    }

    /**
     * A token for the collaboration hub: read-write when the actor may edit, read-only
     * otherwise. Needs a user: the hub attributes every change to the token subject.
     */
    public function collaborationSession(NodeSnapshot|string|int $document, ?int $clientSchemaVersion = null): CollaborationSession
    {
        $node = $this->document($document, Ability::View, OperationKind::Metadata);
        $actor = $this->context->actor->ref()->id;
        if ($this->context->actor->isSystem() || $actor === null) {
            throw MissingActorException::required();
        }
        $flags = $this->guard->abilities($this->context, $node)->flags;
        $ability = match (true) {
            $flags[Ability::Edit->value] ?? false => Ability::Edit,
            $flags[Ability::Comment->value] ?? false => Ability::Comment,
            default => Ability::View,
        };

        return $this->sessions->issue($node, $ability, $actor);
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

    private function write(Node $node, DocumentContent $content, ?string $yjsState, ?int $expectedStateVersion): DocumentSnapshot
    {
        $this->update->execute($this->context, new UpdateDocumentContentInput(
            node: $node,
            content: $content,
            yjsState: $yjsState,
            expectedStateVersion: $expectedStateVersion,
        ));

        return $this->current($node);
    }

    private function current(Node $node): DocumentSnapshot
    {
        $aggregate = $this->query->document($node);

        return $this->mapper->document($aggregate, $aggregate->currentRevision);
    }

    /**
     * The content shape follows the node's editor, as on the HTTP path: a rich-text
     * editor gets a validated TipTap document, any other an opaque snapshot.
     *
     * @param array<string, mixed> $content
     */
    private function contentFor(Node $node, array $content): DocumentContent
    {
        $descriptor = $this->descriptors->forNode($node);
        $handler = $this->handlers->resolve($descriptor, Capability::Edit);

        return $handler->capabilities($descriptor)->richText
            ? TipTapContent::fromArray($content)
            : OpaqueDocumentContent::fromArray($content);
    }

    /**
     * The format label, node mime and content shape of a type key. Until the type
     * registry replaces it, only the built-in types can be created.
     *
     * @return array{string, string, bool}
     */
    private function typeShape(string $type): array
    {
        $known = [
            'vaulter/rich-text' => ['richtext', TipTapHandler::DOCUMENT_MIME, true],
            'vaulter/markdown' => ['markdown', TipTapHandler::DOCUMENT_MIME, true],
        ];
        if ($this->config->spreadsheetsEnabled()) {
            $known['vaulter/spreadsheet'] = ['vaulter/univer', self::SPREADSHEET_MIME, false];
        }
        // The enabled list may name the type key or its format label.
        $enabled = $this->config->enabledDocumentTypes();
        $allowed = array_filter(
            $known,
            static fn (array $shape, string $key): bool => $enabled === [] || in_array($key, $enabled, true) || in_array($shape[0], $enabled, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return $allowed[$type] ?? throw UnknownDocumentTypeException::forType($type, array_keys($allowed));
    }
}
