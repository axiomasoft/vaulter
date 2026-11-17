<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Http\Support\RepresentationTag;

/**
 * A comment on a node: the thread pointer (parent_id), the author, the body, the
 * optional anchor and the resolution. Built from a {@see CommentSnapshot}, never queries. `etag` is the comment's
 * tag (id and version), the If-Match of its resolution and deletion.
 */
#[Internal]
final class CommentResource extends JsonResource
{
    public function __construct(CommentSnapshot $comment)
    {
        parent::__construct($comment);
    }

    /** The ETag header value: the same tag the body carries. */
    public function etag(): string
    {
        /** @var CommentSnapshot $comment */
        $comment = $this->resource;

        return self::tag($comment);
    }

    public static function tag(CommentSnapshot $comment): string
    {
        return RepresentationTag::versioned('comment', $comment->id, $comment->version);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
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
            'version' => $comment->version,
            'etag' => $this->etag(),
            'created_at' => SnapshotValue::iso($comment->createdAt),
        ];
    }
}
