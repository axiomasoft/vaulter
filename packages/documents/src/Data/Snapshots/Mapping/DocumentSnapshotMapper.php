<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots\Mapping;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Support\DocumentTypeKey;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;

/** @internal Builds the documents snapshots from already-loaded rows; it never queries. */
final readonly class DocumentSnapshotMapper
{
    /** Types whose stored JSON is a TipTap document even when it is still empty. */
    private const array RICH_TEXT_TYPES = ['vaulter/rich-text', 'vaulter/markdown'];

    public function __construct(private DocumentsConfig $config) {}

    /**
     * The working state, or the content of $revision when one is given. The head
     * checkpoint (or $revision) must be loaded by the caller.
     */
    public function document(Document $document, ?DocumentRevision $head, ?DocumentRevision $revision = null): DocumentSnapshot
    {
        $typeAvailable = $this->typeAvailable($document->type);
        $source = $revision ?? $head;

        return new DocumentSnapshot(
            nodeId: SnapshotValue::id($document->node_id),
            documentId: SnapshotValue::id($document->getKey()),
            type: $document->type,
            schemaVersion: $revision === null ? $document->schema_version : $revision->schema_version,
            typeAvailable: $typeAvailable,
            // A historical checkpoint is read-only: saving it back would silently drop
            // every change made since.
            editable: $typeAvailable && $revision === null,
            epoch: $document->collab_epoch,
            stateVersion: $document->state_version,
            dirty: $document->hasUncheckpointedChanges(),
            headRevisionId: SnapshotValue::nullableId($document->current_revision_id),
            content: $this->content($document->type, $revision === null ? $document->content : $revision->content),
            revision: $source === null ? null : $this->revision($source, $document),
            contentHash: $revision === null ? $document->content_hash : $revision->content_hash,
            yjsStateSize: $revision === null ? $document->yjs_state_size : $revision->yjs_state_size,
            updatedAt: SnapshotValue::requiredTime($revision === null ? $document->updated_at : $revision->created_at),
            updatedBy: SnapshotValue::nullableId($revision === null ? $document->updated_by : $revision->created_by),
        );
    }

    public function revision(DocumentRevision $revision, Document $document): DocumentRevisionSnapshot
    {
        $id = SnapshotValue::id($revision->getKey());

        return new DocumentRevisionSnapshot(
            id: $id,
            documentId: SnapshotValue::id($document->getKey()),
            nodeId: SnapshotValue::id($document->node_id),
            type: $document->type,
            number: $revision->number,
            schemaVersion: $revision->schema_version,
            stateVersion: $revision->state_version,
            label: $revision->label,
            isAutosave: $revision->is_autosave,
            keepForever: $revision->keep_forever,
            isHead: SnapshotValue::nullableId($document->current_revision_id) === $id,
            restoredFromRevisionId: SnapshotValue::nullableId($revision->restored_from_revision_id),
            contentHash: $revision->content_hash,
            contentSize: $revision->content_size,
            yjsStateSize: $revision->yjs_state_size,
            createdAt: SnapshotValue::requiredTime($revision->created_at),
            createdBy: SnapshotValue::nullableId($revision->created_by),
        );
    }

    public function comment(NodeComment $comment): CommentSnapshot
    {
        return new CommentSnapshot(
            id: SnapshotValue::id($comment->getKey()),
            nodeId: SnapshotValue::id($comment->node_id),
            parentId: SnapshotValue::nullableId($comment->parent_id),
            authorType: $comment->author_type,
            authorId: SnapshotValue::id($comment->author_id),
            body: $comment->body,
            anchor: $comment->anchor,
            resolvedAt: SnapshotValue::time($comment->resolved_at),
            resolvedBy: SnapshotValue::nullableId($comment->resolved_by),
            createdAt: SnapshotValue::requiredTime($comment->created_at),
        );
    }

    /**
     * Rich text decays instead of failing (unknown nodes and marks are stripped), so a
     * document written under an older allow-list still reads; any other type is an
     * opaque snapshot returned as stored.
     *
     * @param array<string, mixed>|null $content
     */
    private function content(string $type, ?array $content): DocumentContent
    {
        if (is_array($content) && ($content['type'] ?? null) === 'doc') {
            return TipTapContent::decayFrom($content);
        }
        if ($content === null && in_array($type, self::RICH_TEXT_TYPES, true)) {
            return TipTapContent::empty();
        }

        return OpaqueDocumentContent::fromArray($content ?? []);
    }

    private function typeAvailable(string $type): bool
    {
        $enabled = $this->config->enabledDocumentTypes();

        // The enabled list may name the type key or its format label.
        return $enabled === [] || in_array($type, $enabled, true) || in_array(DocumentTypeKey::toFormat($type), $enabled, true);
    }
}
