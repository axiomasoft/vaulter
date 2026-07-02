<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Data\CreateNodeCommentData;
use Vaulter\Documents\Events\NodeCommentAdded;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Documents\Repositories\NodeCommentStoreRepository;
use Vaulter\Models\Drive\Node;

/**
 * Add a comment to a node (ADR-0014 шаг 1). Inserts the row in a transaction, then
 * dispatches {@see NodeCommentAdded} AFTER commit (the event body reads a committed
 * row, and the broadcast lands on the node's collaboration channel).
 */
final readonly class AddNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
    ) {}

    public function execute(Node $node, CreateNodeCommentData $data): NodeComment
    {
        $comment = DB::transaction(fn (): NodeComment => $this->comments->create([
            'node_id' => $node->getKey(),
            'parent_id' => $data->parentId,
            'author_type' => $data->authorType,
            'author_id' => $data->authorId,
            'body' => $data->body,
            'anchor' => $data->anchor,
        ]));

        event(new NodeCommentAdded($comment));

        return $comment;
    }
}
