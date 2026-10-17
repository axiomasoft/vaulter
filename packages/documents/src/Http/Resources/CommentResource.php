<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;

/**
 * A comment on a node: the thread pointer (parent_id), the author, the body, the
 * optional anchor and the resolution. Built from a {@see CommentSnapshot}, never queries.
 */
#[Internal]
final class CommentResource extends JsonResource
{
    public function __construct(CommentSnapshot $comment)
    {
        parent::__construct($comment);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var CommentSnapshot $comment */
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'node_id' => $comment->nodeId,
            'parent_id' => $comment->parentId,
            'author' => [
                'type' => $comment->authorType,
                'id' => $comment->authorId,
            ],
            'body' => $comment->body,
            'anchor' => $comment->anchor,
            'resolved_at' => SnapshotValue::iso($comment->resolvedAt),
            'resolved_by' => $comment->resolvedBy,
            'created_at' => SnapshotValue::iso($comment->createdAt),
        ];
    }
}
