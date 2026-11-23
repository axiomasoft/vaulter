<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Comments;

use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Models\Comments\NodeComment;

/**
 * Write-side persistence for the NODE-attached comments. resolve()
 * stamps the (resolved_at, resolved_by) tombstone; delete() is permanent (there is no
 * soft-delete on this table).
 */
#[Internal]
final class NodeCommentStoreRepository
{
    public function __construct(
        private readonly DocumentsOptions $documentsConfig,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): NodeComment
    {
        // forceFill: $attributes is a server-built literal array
        // from AddCommentAction, never raw request input.
        $model = $this->documentsConfig->model('node_comment');
        $comment = new $model;
        $comment->forceFill($attributes);
        $comment->save();

        return $comment;
    }

    /** The comment row, locked for the rest of the caller's transaction; null when it is gone. */
    public function lock(NodeComment $comment): ?NodeComment
    {
        $model = $this->documentsConfig->model('node_comment');

        return $model::query()->whereKey($comment->getKey())->lockForUpdate()->first();
    }

    public function resolve(NodeComment $comment, int|string|null $resolvedBy = null): NodeComment
    {
        $comment->forceFill([
            'resolved_at' => Carbon::now(),
            'resolved_by' => $resolvedBy,
        ])->save();

        return $comment;
    }

    public function reopen(NodeComment $comment): NodeComment
    {
        $comment->forceFill(['resolved_at' => null, 'resolved_by' => null, 'updated_at' => Carbon::now()])->save();

        return $comment;
    }

    public function delete(NodeComment $comment): void
    {
        $comment->delete();
    }
}
