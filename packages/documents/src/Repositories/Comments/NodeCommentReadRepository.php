<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Comments;

use Illuminate\Database\Eloquent\Builder;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Drive\Models\Node;

/**
 * Read-side access to the NODE-attached comments. The per-node feed
 * is oldest-first (a thread reads top-to-bottom); find() resolves a single comment for
 * the resolve/delete paths.
 */
#[Internal]
final class NodeCommentReadRepository
{
    public function __construct(
        private readonly DocumentsOptions $documentsConfig,
    ) {}

    /**
     * @return Builder<NodeComment>
     */
    public function forNode(Node $node): Builder
    {
        $model = $this->documentsConfig->model('node_comment');

        return $model::query()
            ->where('node_id', $node->getKey())
            ->oldest('created_at');
    }

    public function find(int|string $id): ?NodeComment
    {
        $model = $this->documentsConfig->model('node_comment');

        return $model::query()->find($id);
    }
}
