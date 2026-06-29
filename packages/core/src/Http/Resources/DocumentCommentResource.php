<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\DocumentComment;

/**
 * @mixin DocumentComment
 */
final class DocumentCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentComment $comment */
        $comment = $this->resource;

        return [
            'id' => $comment->getKey(),
            'document_id' => $comment->document_id,
            'parent_id' => $comment->parent_id,
            'author' => [
                'type' => $comment->author_type,
                'id' => $comment->author_id,
            ],
            'body' => $comment->body,
            'anchor' => $comment->anchor,
            'resolved_at' => $comment->resolved_at,
            'created_at' => $comment->created_at,
        ];
    }
}
