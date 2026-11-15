<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Idempotency\ReceiptFields;

/**
 * What a comment deletion keeps in its receipt (a delete receipt carries the provable original authorization
 * scope): the id of the removed comment, the node it hung on and whether the right to delete it was proven as
 * its author or by Manage on the node — never its body or author. A replay checks that basis now, because the comment
 * itself is gone: an author keeps the right to what was theirs, a deletion by Manage needs Manage still.
 */
#[Internal]
final readonly class CommentDeleteReceipt
{
    public function __construct(
        public string $id,
        public string $nodeId,
        public string $driveId,
        public bool $byAuthor,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'node_id' => $this->nodeId, 'drive_id' => $this->driveId, 'by_author' => $this->byAuthor];
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            id: ReceiptFields::text($body, 'id', 'comment'),
            nodeId: ReceiptFields::text($body, 'node_id', 'comment'),
            driveId: ReceiptFields::text($body, 'drive_id', 'comment'),
            byAuthor: ReceiptFields::bool($body, 'by_author', 'comment'),
        );
    }
}
