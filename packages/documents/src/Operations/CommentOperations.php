<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Vaulter\Access\OperationGuard;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Documents\Actions\Comments\AddCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Support\Identity;

/**
 * Comments on any node of one drive: `Documents::comments($drive)`.
 *
 * Reading a thread needs View, writing in it needs Comment. Resolving a thread needs
 * Comment and either authorship or Edit; deleting a comment needs authorship or Manage.
 * A comment id is resolved only inside the addressed node, so another node's comment
 * is "not found" (comment_not_found). The drive profile must enable comments.
 */
final readonly class CommentOperations
{
    public function __construct(
        private OperationContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ProfilePolicy $profiles,
        private DocumentQuery $query,
        private DocumentSnapshotMapper $mapper,
        private AddCommentAction $add,
        private ResolveCommentAction $resolve,
        private DeleteCommentAction $delete,
    ) {}

    /** @return Page<CommentSnapshot> oldest first */
    public function list(NodeSnapshot|string|int $node, ?ListOptions $options = null): Page
    {
        $node = $this->node($node, Ability::View, OperationKind::Metadata);
        $page = $this->query->comments($node, $options ?? new ListOptions);

        return new Page(
            items: array_values(array_map(fn (NodeComment $comment): CommentSnapshot => $this->mapper->comment($comment), $page->items())),
            nextCursor: $page->nextCursor()?->encode(),
        );
    }

    public function add(NodeSnapshot|string|int $node, AddCommentInput $input): CommentSnapshot
    {
        $node = $this->node($node, Ability::Comment, OperationKind::Mutation);
        if ($input->parentId !== null) {
            // A reply threads only under a comment of the same node.
            $this->query->comment($node, $input->parentId);
        }

        return $this->mapper->comment($this->add->execute($this->context, $node, $input));
    }

    public function resolve(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment): CommentSnapshot
    {
        $node = $this->node($node, Ability::Comment, OperationKind::Mutation);
        $row = $this->query->comment($node, $comment);
        if (!$this->isAuthor($row)) {
            $this->guard->authorize($this->context, Ability::Edit, $node, OperationKind::Mutation);
        }

        return $this->mapper->comment($this->resolve->execute($this->context, new ResolveCommentInput($row)));
    }

    public function delete(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment): void
    {
        // Deleting is a mutation even for the author, so an archived drive refuses it.
        $node = $this->node($node, Ability::View, OperationKind::Mutation);
        $row = $this->query->comment($node, $comment);
        if (!$this->isAuthor($row)) {
            $this->guard->authorize($this->context, Ability::Manage, $node, OperationKind::Mutation);
        }

        $this->delete->execute($this->context, new DeleteCommentInput($row));
    }

    private function node(NodeSnapshot|string|int $key, Ability $ability, OperationKind $operation): Node
    {
        $node = $this->locator->find($this->context, $key);
        $this->guard->authorize($this->context, $ability, $node, $operation);
        $this->profiles->assertComments($this->context->drive);

        return $node;
    }

    private function isAuthor(NodeComment $comment): bool
    {
        $actor = $this->context->actor->ref();

        return !$this->context->actor->isSystem()
            && $actor->id !== null
            && $actor->type === $comment->author_type
            && Identity::stringify($actor->id) === Identity::stringify($comment->author_id);
    }
}
