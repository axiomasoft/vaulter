<?php

declare(strict_types=1);

namespace Vaulter\Documents\Operations;

use Closure;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Documents\Actions\Comments\AddCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Idempotency\CommentDeleteReceipt;
use Vaulter\Documents\Idempotency\CommentReceiptCodec;
use Vaulter\Documents\Idempotency\CommentReplayAuthorizer;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

/**
 * Comments on any node of one drive: `Documents::comments($drive)`.
 *
 * Reading a thread needs View, writing in it needs Comment. Resolving a thread needs
 * Comment and either authorship or Edit; deleting a comment needs authorship or Manage.
 * A comment id is resolved only inside the addressed node, so another node's comment
 * is "not found" (comment_not_found). The drive profile must enable comments.
 *
 * Every write is idempotent by key: the receipt is the comment, or for a deletion the ids and the basis the right
 * was proven on, never the body. A comment has a version of its own, which a resolution raises: pass
 * CommentSnapshot::$version back as the expected version of resolve() and delete() to refuse a write on a comment that
 * changed or went since it was read (412 stale_write).
 */
#[Api]
final readonly class CommentOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ProfilePolicy $profiles,
        private DocumentQuery $query,
        private DocumentSnapshotMapper $mapper,
        private AddCommentAction $add,
        private ResolveCommentAction $resolve,
        private DeleteCommentAction $delete,
        private OperationRunner $runner,
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
        $nodeId = self::idOf($node);
        $idempotency = new IdempotentWrite(
            codec: new CommentReceiptCodec,
            authorizer: CommentReplayAuthorizer::forAdd($this->context, $this->locator, $this->guard, $this->profiles, $nodeId),
            fingerprint: ['node' => $nodeId, 'body' => $input->body, 'parent' => $input->parentId, 'anchor' => $input->anchor],
        );

        return $this->write('add', function () use ($node, $input): CommentSnapshot {
            $node = $this->node($node, Ability::Comment, OperationKind::Mutation);
            if ($input->parentId !== null) {
                // A reply threads only under a comment of the same node.
                $this->query->comment($node, $input->parentId);
            }

            return $this->mapper->comment($this->add->execute($this->context, $node, $input));
        }, $idempotency);
    }

    public function resolve(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, ?int $expectedVersion = null): CommentSnapshot
    {
        $nodeId = self::idOf($node);
        $authorizer = CommentReplayAuthorizer::forResolve($this->context, $this->locator, $this->guard, $this->profiles, $nodeId);
        $idempotency = new IdempotentWrite(
            codec: new CommentReceiptCodec,
            authorizer: $authorizer,
            fingerprint: ['node' => $nodeId, 'comment' => self::commentId($comment), 'expected_version' => $expectedVersion],
        );

        return $this->write('resolve', function () use ($node, $comment, $expectedVersion, $authorizer): CommentSnapshot {
            [, $row, $byAuthor] = $this->authorizedForResolve($node, $comment);
            if ($byAuthor) {
                // A refusal past this point (412 stale_write) is stored on the authorship it proved, not on Edit.
                $authorizer->provedByAuthorship();
            }

            return $this->mapper->comment($this->resolve->execute($this->context, new ResolveCommentInput($row, $expectedVersion)));
        }, $idempotency);
    }

    public function delete(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, ?int $expectedVersion = null): void
    {
        $nodeId = self::idOf($node);
        // The receipt keeps the comment's id, its node and the basis of the right (ID2), never the comment.
        $authorizer = CommentReplayAuthorizer::forDelete($this->context, $this->locator, $this->guard, $this->profiles, $nodeId);
        $idempotency = new IdempotentWrite(
            codec: new CommentReceiptCodec,
            authorizer: $authorizer,
            fingerprint: ['node' => $nodeId, 'comment' => self::commentId($comment), 'expected_version' => $expectedVersion],
        );

        $this->write('delete', function () use ($node, $comment, $expectedVersion, $authorizer): CommentDeleteReceipt {
            [$node, $row, $byAuthor] = $this->authorizedForDelete($node, $comment);
            if ($byAuthor) {
                $authorizer->provedByAuthorship();
            }
            $receipt = new CommentDeleteReceipt(id: Identity::stringify($row->getKey()), nodeId: Identity::stringify($node->getKey()), driveId: Identity::stringify($this->context->driveId()), byAuthor: $byAuthor);
            $this->delete->execute($this->context, new DeleteCommentInput($row, $expectedVersion));

            return $receipt;
        }, $idempotency);
    }

    /**
     * The comment a resolution or deletion would act on, authorized exactly as that write is, without writing: the
     * HTTP If-Match is compared with it, so a caller who may not write learns nothing from a 428 or a 412.
     */
    #[Internal]
    public function forWrite(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment, bool $delete): CommentSnapshot
    {
        [, $row] = $delete ? $this->authorizedForDelete($node, $comment) : $this->authorizedForResolve($node, $comment);

        return $this->mapper->comment($row);
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, IdempotentWrite $idempotency): mixed
    {
        return $this->runner->write(new OperationSpec('documents.comments.'.$verb, new ModuleId('documents')), $this->context->operation, $attempt, $idempotency);
    }

    /** @return array{0: Node, 1: NodeComment, 2: bool} the basis of the right: authorship, else Edit */
    private function authorizedForResolve(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment): array
    {
        $node = $this->node($node, Ability::Comment, OperationKind::Mutation);
        $row = $this->query->comment($node, $comment);
        $byAuthor = $this->isAuthor($row);
        if (!$byAuthor) {
            $this->guard->authorize($this->context, Ability::Edit, $node, OperationKind::Mutation);
        }

        return [$node, $row, $byAuthor];
    }

    /** @return array{0: Node, 1: NodeComment, 2: bool} the basis of the right: authorship, else Manage */
    private function authorizedForDelete(NodeSnapshot|string|int $node, CommentSnapshot|string|int $comment): array
    {
        // Deleting is a mutation even for the author, so an archived drive refuses it.
        $node = $this->node($node, Ability::View, OperationKind::Mutation);
        $row = $this->query->comment($node, $comment);
        $byAuthor = $this->isAuthor($row);
        if (!$byAuthor) {
            $this->guard->authorize($this->context, Ability::Manage, $node, OperationKind::Mutation);
        }

        return [$node, $row, $byAuthor];
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private static function commentId(CommentSnapshot|string|int $comment): string
    {
        return $comment instanceof CommentSnapshot ? $comment->id : (string) $comment;
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
        return CommentReplayAuthorizer::isAuthor($this->context, $comment->author_type, $comment->author_id);
    }
}
