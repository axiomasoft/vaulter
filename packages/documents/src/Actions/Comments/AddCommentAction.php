<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Events\Comments\CommentAdded;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

/**
 * Add a comment to a node (ADR-0014 шаг 1). The author is the operation's actor — a
 * system operation has nobody to attribute the comment to and is refused. Inserts the
 * row in a transaction, then dispatches {@see CommentAdded} AFTER commit (the event
 * body reads a committed row, and the broadcast lands on the node's collaboration
 * channel).
 *
 * The node is a separate argument because AddCommentInput is the public input of
 * CommentOperations::add(), which names the node on its own.
 */
final readonly class AddCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(OperationContext $context, Node $node, AddCommentInput $data): NodeComment
    {
        $author = $context->actor->ref();
        if ($context->actor->isSystem() || $author->type === null || $author->id === null) {
            throw MissingActorException::required();
        }

        $comment = $this->database->transaction(fn (): NodeComment => $this->comments->create([
            'node_id' => $node->getKey(),
            'parent_id' => $data->parentId,
            'author_type' => $author->type,
            'author_id' => $author->id,
            'body' => $data->body,
            'anchor' => $data->anchor,
        ]));

        event(new CommentAdded($comment));

        return $comment;
    }
}
