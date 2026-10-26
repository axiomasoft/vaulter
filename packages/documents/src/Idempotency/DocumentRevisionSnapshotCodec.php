<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Drive\Idempotency\ReceiptFields;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a write that answers a {@see DocumentRevisionSnapshot} (documents.snapshot): the checkpoint built at
 * commit, field by field. A checkpoint carries no content, only its hash and sizes. Version 1.
 */
#[Internal]
final class DocumentRevisionSnapshotCodec implements ReceiptCodec
{
    public const string KIND = 'documents.revision-snapshot';

    private const string RECEIPT = 'document revision';

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
        return self::toArray(self::snapshot($result));
    }

    public function decode(array $body, int $version): DocumentRevisionSnapshot
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);

        return self::fromArray($body);
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', self::snapshot($result)->nodeId);
    }

    /** @return array<string, mixed> */
    public static function toArray(DocumentRevisionSnapshot $revision): array
    {
        return [
            'id' => $revision->id,
            'document_id' => $revision->documentId,
            'node_id' => $revision->nodeId,
            'type' => $revision->type,
            'number' => $revision->number,
            'schema_version' => $revision->schemaVersion,
            'collab_protocol' => $revision->collabProtocol,
            'collab_state_format' => $revision->collabStateFormat,
            'collab_epoch' => $revision->epoch,
            'state_version' => $revision->stateVersion,
            'label' => $revision->label,
            'is_autosave' => $revision->isAutosave,
            'keep_forever' => $revision->keepForever,
            'is_head' => $revision->isHead,
            'restored_from_revision_id' => $revision->restoredFromRevisionId,
            'content_hash' => $revision->contentHash,
            'content_size' => $revision->contentSize,
            'collab_state_size' => $revision->collabStateSize,
            'created_at' => ReceiptFields::time($revision->createdAt),
            'created_by' => $revision->createdBy,
        ];
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): DocumentRevisionSnapshot
    {
        return new DocumentRevisionSnapshot(
            id: ReceiptFields::text($body, 'id', self::RECEIPT),
            documentId: ReceiptFields::text($body, 'document_id', self::RECEIPT),
            nodeId: ReceiptFields::text($body, 'node_id', self::RECEIPT),
            type: ReceiptFields::text($body, 'type', self::RECEIPT),
            number: ReceiptFields::int($body, 'number', self::RECEIPT),
            schemaVersion: ReceiptFields::int($body, 'schema_version', self::RECEIPT),
            collabProtocol: ReceiptFields::nullableText($body, 'collab_protocol'),
            collabStateFormat: ReceiptFields::nullableText($body, 'collab_state_format'),
            epoch: ReceiptFields::int($body, 'collab_epoch', self::RECEIPT),
            stateVersion: ReceiptFields::int($body, 'state_version', self::RECEIPT),
            label: ReceiptFields::nullableText($body, 'label'),
            isAutosave: ReceiptFields::bool($body, 'is_autosave', self::RECEIPT),
            keepForever: ReceiptFields::bool($body, 'keep_forever', self::RECEIPT),
            isHead: ReceiptFields::bool($body, 'is_head', self::RECEIPT),
            restoredFromRevisionId: ReceiptFields::nullableText($body, 'restored_from_revision_id'),
            contentHash: ReceiptFields::nullableText($body, 'content_hash'),
            contentSize: ReceiptFields::nullableInt($body, 'content_size'),
            collabStateSize: ReceiptFields::nullableInt($body, 'collab_state_size'),
            createdAt: ReceiptFields::requiredTime($body, 'created_at', self::RECEIPT),
            createdBy: ReceiptFields::nullableText($body, 'created_by'),
        );
    }

    private static function snapshot(mixed $result): DocumentRevisionSnapshot
    {
        return $result instanceof DocumentRevisionSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a DocumentRevisionSnapshot, %s given.', self::KIND, get_debug_type($result)));
    }
}
