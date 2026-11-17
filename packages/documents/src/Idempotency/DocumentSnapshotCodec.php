<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Drive\Idempotency\ReceiptFields;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a write that answers a {@see DocumentSnapshot} (documents.update, documents.restore): the snapshot
 * built at commit, field by field, so a replay returns what the first request returned — its content tag is
 * historical and never satisfies a later If-Match by itself. The content is stored as its JSON payload and read back
 * the way a stored state is read ({@see ContentDecoder::read()}), so a rich-text document comes back as rich text.
 * Version 1.
 */
#[Internal]
final readonly class DocumentSnapshotCodec implements ReceiptCodec
{
    public const string KIND = 'documents.document-snapshot';

    private const string RECEIPT = 'document';

    public function __construct(private ContentDecoder $decoder) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        $document = self::snapshot($result);

        return [
            'node_id' => $document->nodeId,
            'document_id' => $document->documentId,
            'type' => $document->type,
            'schema_version' => $document->schemaVersion,
            'collab_protocol' => $document->collabProtocol,
            'collab_state_format' => $document->collabStateFormat,
            'type_available' => $document->typeAvailable,
            'editable' => $document->editable,
            'epoch' => $document->epoch,
            'state_version' => $document->stateVersion,
            'dirty' => $document->dirty,
            'head_revision_id' => $document->headRevisionId,
            'content' => $document->content->toArray(),
            'revision' => $document->revision === null ? null : DocumentRevisionSnapshotCodec::toArray($document->revision),
            'content_hash' => $document->contentHash,
            'collab_state_size' => $document->collabStateSize,
            'updated_at' => ReceiptFields::time($document->updatedAt),
            'updated_by' => $document->updatedBy,
        ];
    }

    public function decode(array $body, int $version): DocumentSnapshot
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);
        $type = ReceiptFields::text($body, 'type', self::RECEIPT);
        $schemaVersion = ReceiptFields::int($body, 'schema_version', self::RECEIPT);
        /** @var array<string, mixed> $stored */
        $stored = ReceiptFields::object($body, 'content', self::RECEIPT);
        $revision = $body['revision'] ?? null;
        // The stored payload is already at the schema version the snapshot names: it is read as the type's current one
        // so that it is not upgraded a second time.
        $readAs = $this->decoder->available($type) ? $this->decoder->type($type)->schemaVersion() : $schemaVersion;

        return new DocumentSnapshot(
            nodeId: ReceiptFields::text($body, 'node_id', self::RECEIPT),
            documentId: ReceiptFields::text($body, 'document_id', self::RECEIPT),
            type: $type,
            schemaVersion: $schemaVersion,
            typeAvailable: ReceiptFields::bool($body, 'type_available', self::RECEIPT),
            editable: ReceiptFields::bool($body, 'editable', self::RECEIPT),
            epoch: ReceiptFields::int($body, 'epoch', self::RECEIPT),
            stateVersion: ReceiptFields::int($body, 'state_version', self::RECEIPT),
            dirty: ReceiptFields::bool($body, 'dirty', self::RECEIPT),
            headRevisionId: ReceiptFields::nullableText($body, 'head_revision_id'),
            content: $this->decoder->read($type, $stored, $readAs)->content,
            revision: is_array($revision) ? DocumentRevisionSnapshotCodec::fromArray($revision) : null,
            contentHash: ReceiptFields::nullableText($body, 'content_hash'),
            collabStateSize: ReceiptFields::nullableInt($body, 'collab_state_size'),
            updatedAt: ReceiptFields::requiredTime($body, 'updated_at', self::RECEIPT),
            updatedBy: ReceiptFields::nullableText($body, 'updated_by'),
            collabProtocol: ReceiptFields::nullableText($body, 'collab_protocol'),
            collabStateFormat: ReceiptFields::nullableText($body, 'collab_state_format'),
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', self::snapshot($result)->nodeId);
    }

    private static function snapshot(mixed $result): DocumentSnapshot
    {
        return $result instanceof DocumentSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a DocumentSnapshot, %s given.', self::KIND, get_debug_type($result)));
    }
}
