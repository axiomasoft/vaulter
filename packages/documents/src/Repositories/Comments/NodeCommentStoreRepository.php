<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Comments;

use Illuminate\Support\Carbon;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Comments\NodeComment;

/**
 * Write-side persistence for the NODE-attached comments (ADR-0014 шаг 1). resolve()
 * stamps the (resolved_at, resolved_by) tombstone; delete() is permanent (legacy
 * parity — there is no soft-delete on this table).
 */
final class NodeCommentStoreRepository
{
    public function __construct(
        private readonly DocumentsConfig $documentsConfig,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): NodeComment
    {
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from AddCommentAction, never raw request input.
        $model = $this->documentsConfig->model('node_comment');
        $comment = new $model;
        $comment->forceFill($attributes);
        $comment->save();

        return $comment;
    }

    public function resolve(NodeComment $comment, int|string|null $resolvedBy = null): NodeComment
    {
        $comment->forceFill([
            'resolved_at' => Carbon::now(),
            'resolved_by' => $resolvedBy,
        ])->save();

        return $comment;
    }

    public function delete(NodeComment $comment): void
    {
        $comment->delete();
    }
}
