<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Documents\Models\Comments\NodeComment;

/**
 * The read shape of a node comment (ADR-0014 шаг 1): the thread pointer (parent_id),
 * the author morph, the body, the optional anchor and the resolution tombstone.
 * Read-only — transforms an already-loaded NodeComment, never queries.
 *
 * @mixin NodeComment
 */
final class NodeCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeComment $comment */
        $comment = $this->resource;

        return [
            'id' => $comment->getKey(),
            'node_id' => $comment->node_id,
            'parent_id' => $comment->parent_id,
            'author' => [
                'type' => $comment->author_type,
                'id' => $comment->author_id,
            ],
            'body' => $comment->body,
            'anchor' => $comment->anchor,
            'resolved_at' => $comment->resolved_at,
            'resolved_by' => $comment->resolved_by,
            'created_at' => $comment->created_at,
        ];
    }
}
