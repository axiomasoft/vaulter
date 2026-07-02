<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Documents\DocumentsModels;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Models\Drive\Node;

/**
 * Read-side access to the NODE-attached comments (ADR-0014 шаг 1). The per-node feed
 * is oldest-first (a thread reads top-to-bottom); find() resolves a single comment for
 * the resolve/delete paths.
 */
final class NodeCommentReadRepository
{
    /**
     * @return Builder<NodeComment>
     */
    public function forNode(Node $node): Builder
    {
        $model = DocumentsModels::comment();

        return $model::query()
            ->where('node_id', $node->getKey())
            ->oldest('created_at');
    }

    public function find(int|string $id): ?NodeComment
    {
        $model = DocumentsModels::comment();

        return $model::query()->find($id);
    }
}
