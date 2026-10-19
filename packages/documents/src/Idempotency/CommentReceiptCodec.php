<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Drive\Idempotency\ReceiptFields;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a comment write: `comment` — the {@see CommentSnapshot} of documents.comments.add and .resolve, built
 * at commit field by field (its version and tag are historical); `deleted` — the {@see CommentDeleteReceipt} of
 * documents.comments.delete. Both are about the comment's node. Version 1.
 */
#[Internal]
final class CommentReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'documents.comment-receipt';

    private const string RECEIPT = 'comment';

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
        return match (true) {
            $result instanceof CommentDeleteReceipt => ['type' => 'deleted', 'deleted' => $result->toArray()],
            $result instanceof CommentSnapshot => ['type' => 'comment', 'comment' => [
                'id' => $result->id,
                'node_id' => $result->nodeId,
                'parent_id' => $result->parentId,
                'author_type' => $result->authorType,
                'author_id' => $result->authorId,
                'body' => $result->body,
                'anchor' => $result->anchor,
                'resolved_at' => ReceiptFields::time($result->resolvedAt),
                'resolved_by' => $result->resolvedBy,
                'created_at' => ReceiptFields::time($result->createdAt),
                'version' => $result->version,
            ]],
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a CommentSnapshot or a CommentDeleteReceipt, %s given.', self::KIND, get_debug_type($result))),
        };
    }

    public function decode(array $body, int $version): CommentSnapshot|CommentDeleteReceipt
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);

        return match ($body['type'] ?? null) {
            'deleted' => CommentDeleteReceipt::fromArray(ReceiptFields::object($body, 'deleted', self::RECEIPT)),
            'comment' => self::snapshot(ReceiptFields::object($body, 'comment', self::RECEIPT)),
            default => throw new LogicException('[Vaulter] A comment receipt has no known type.'),
        };
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', match (true) {
            $result instanceof CommentDeleteReceipt => $result->nodeId,
            $result instanceof CommentSnapshot => $result->nodeId,
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s is about a comment, %s given.', self::KIND, get_debug_type($result))),
        });
    }

    /** @param array<array-key, mixed> $fields */
    private static function snapshot(array $fields): CommentSnapshot
    {
        $anchor = $fields['anchor'] ?? null;

        /** @var array<string, mixed>|null $anchor */
        return new CommentSnapshot(
            id: ReceiptFields::text($fields, 'id', self::RECEIPT),
            nodeId: ReceiptFields::text($fields, 'node_id', self::RECEIPT),
            parentId: ReceiptFields::nullableText($fields, 'parent_id'),
            authorType: ReceiptFields::text($fields, 'author_type', self::RECEIPT),
            authorId: ReceiptFields::text($fields, 'author_id', self::RECEIPT),
            body: ReceiptFields::text($fields, 'body', self::RECEIPT),
            anchor: is_array($anchor) ? $anchor : null,
            resolvedAt: ReceiptFields::parse($fields, 'resolved_at', self::RECEIPT),
            resolvedBy: ReceiptFields::nullableText($fields, 'resolved_by'),
            createdAt: ReceiptFields::requiredTime($fields, 'created_at', self::RECEIPT),
            version: ReceiptFields::int($fields, 'version', self::RECEIPT),
        );
    }
}
