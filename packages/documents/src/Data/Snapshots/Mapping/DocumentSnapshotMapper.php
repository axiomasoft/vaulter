<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots\Mapping;

use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/** Builds the documents snapshots from already-loaded rows; it never queries. */
#[Internal]
final readonly class DocumentSnapshotMapper
{
    public function __construct(private ContentDecoder $decoder) {}

    /**
     * The working state, or the content of $revision when one is given. The head
     * checkpoint (or $revision) must be loaded by the caller.
     */
    public function document(Document $document, ?DocumentRevision $head, ?DocumentRevision $revision = null): DocumentSnapshot
    {
        $typeKey = $revision === null ? $document->type : $revision->type;
        $typeAvailable = $this->decoder->available($typeKey);
        $source = $revision ?? $head;
        $schemaVersion = $revision === null ? $document->schema_version : $revision->schema_version;
        $read = $this->decoder->read($typeKey, $revision === null ? $document->content : $revision->content, $schemaVersion);

        return new DocumentSnapshot(
            nodeId: SnapshotValue::id($document->node_id),
            documentId: SnapshotValue::id($document->getKey()),
            type: $typeKey,
            schemaVersion: $schemaVersion,
            typeAvailable: $typeAvailable,
            // Read-only when saving it back would lose something: every change made since
            // a historical checkpoint, the parts a degraded read dropped, or the fields of
            // a schema newer than this installation's type.
            editable: $typeAvailable
                && $revision === null
                && $read->lossless
                && $this->decoder->schemaWritable($this->decoder->type($typeKey), $schemaVersion),
            epoch: $document->collab_epoch,
            stateVersion: $document->state_version,
            dirty: $document->hasUncheckpointedChanges(),
            headRevisionId: SnapshotValue::nullableId($document->current_revision_id),
            content: $read->content,
            revision: $source === null ? null : $this->revision($source, $document),
            contentHash: $revision === null ? $document->content_hash : $revision->content_hash,
            collabProtocol: $revision === null ? $document->collab_protocol : $revision->collab_protocol,
            collabStateFormat: $revision === null ? $document->collab_state_format : $revision->collab_state_format,
            collabStateSize: $revision === null ? $document->collab_state_size : $revision->collab_state_size,
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
            type: $revision->type,
            number: $revision->number,
            schemaVersion: $revision->schema_version,
            collabProtocol: $revision->collab_protocol,
            collabStateFormat: $revision->collab_state_format,
            epoch: $revision->collab_epoch,
            stateVersion: $revision->state_version,
            label: $revision->label,
            isAutosave: $revision->is_autosave,
            keepForever: $revision->keep_forever,
            isHead: SnapshotValue::nullableId($document->current_revision_id) === $id,
            restoredFromRevisionId: SnapshotValue::nullableId($revision->restored_from_revision_id),
            contentHash: $revision->content_hash,
            contentSize: $revision->content_size,
            collabStateSize: $revision->collab_state_size,
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
            version: $comment->version,
        );
    }
}
