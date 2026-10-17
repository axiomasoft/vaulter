<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Events\Comments\CommentAdded;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Access\MissingActorException;

/**
 * Add a comment to a node (docs/adr/0014-legacy-docvault-strangler.md шаг 1). The author is the operation's actor — a
 * system operation has nobody to attribute the comment to and is refused.
 *
 * Lock order T (shared) → N (the node) → the comment row (F06): under both
 * locks the drive must still be writable and the node still live, so a trash or an
 * archive that committed first leaves no comment behind. The event is raised inside
 * the unit of work and delivered after the OUTERMOST commit, so a rollback broadcasts
 * nothing ({@see CommentAdded}).
 *
 * The node is a separate argument because AddCommentInput is the public input of
 * CommentOperations::add(), which names the node on its own.
 */
#[Internal]
final readonly class AddCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, Node $node, AddCommentInput $data): NodeComment
    {
        $author = $context->actor->ref();
        if ($context->actor->isSystem() || $author->type === null || $author->id === null) {
            throw MissingActorException::required();
        }

        return $this->database->transaction(function () use ($context, $node, $data, $author): NodeComment {
            $driveId = $context->driveId();
            $this->database->lockAggregateShared('drives', $driveId);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($node->id);
            }
            $this->tree->assertLive($current);

            $comment = $this->comments->create([
                'node_id' => $current->getKey(),
                'parent_id' => $data->parentId,
                'author_type' => $author->type,
                'author_id' => $author->id,
                'body' => $data->body,
                'anchor' => $data->anchor,
            ]);
            $comment->setRelation('node', $current);
            $this->recorder->record(new CommentAdded(comment: $comment, actor: $author, correlationId: $context->correlationId));

            return $comment;
        });
    }
}
