<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Actions\AddNodeCommentAction;
use Vaulter\Documents\Actions\DeleteNodeCommentAction;
use Vaulter\Documents\Actions\ResolveNodeCommentAction;
use Vaulter\Documents\Data\CreateNodeCommentData;
use Vaulter\Documents\Http\Requests\StoreNodeCommentRequest;
use Vaulter\Documents\Http\Resources\NodeCommentResource;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Documents\Repositories\NodeCommentReadRepository;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\CommentController;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/**
 * The NODE-attached comment HTTP surface (ADR-0014 шаг 1), the additive twin of the
 * legacy {@see CommentController} (document_id-bound, left
 * untouched). Thin: authorization runs through the Vaulter-owned AccessResolver,
 * persistence through the Actions, reads through the repository — no static model
 * queries here (the M1c-http arch rule).
 *
 * Authorization (§2.6 / H.2):
 *   - index   → Ability::View    (anyone who can see the node reads its thread);
 *   - store   → Ability::Comment (the dedicated comment bit);
 *   - resolve → the AUTHOR of the comment OR Ability::Manage on the node;
 *   - destroy → the AUTHOR of the comment OR Ability::Manage on the node.
 */
final class NodeCommentController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly NodeCommentReadRepository $comments,
    ) {}

    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardAbility($request, Ability::View, $node);

        return NodeCommentResource::collection($this->comments->forNode($node)->get());
    }

    public function store(StoreNodeCommentRequest $request, Node $node, AddNodeCommentAction $action): JsonResponse
    {
        $this->guardAbility($request, Ability::Comment, $node);

        $data = $this->withValidatedParent($request->toData(), $node);

        return NodeCommentResource::make($action->execute($node, $data))
            ->response()
            ->setStatusCode(201);
    }

    public function resolve(
        Request $request,
        Node $node,
        int|string $comment,
        ResolveNodeCommentAction $action,
    ): NodeCommentResource {
        $this->authorOrManageActor($request, $node, $comment);

        $resolved = $action->execute($this->findComment($node, $comment), $this->actorId($request));

        return NodeCommentResource::make($resolved);
    }

    public function destroy(
        Request $request,
        Node $node,
        int|string $comment,
        DeleteNodeCommentAction $action,
    ): Response {
        $this->authorOrManageActor($request, $node, $comment);

        $action->execute($this->findComment($node, $comment), $this->actorId($request));

        return response()->noContent();
    }

    /**
     * A reply must attach to a comment that lives on the SAME node — a cross-node
     * parent_id would thread a reply onto a foreign thread. An unknown/foreign parent
     * is a 404 (the parent is not addressable under this node).
     */
    private function withValidatedParent(CreateNodeCommentData $data, Node $node): CreateNodeCommentData
    {
        if ($data->parentId === null) {
            return $data;
        }

        // findComment throws a 404 when the parent does not belong to this node.
        $this->findComment($node, $data->parentId);

        return $data;
    }

    /**
     * Assert the actor may resolve/delete the comment: they are its author OR hold
     * Manage on the node. An unauthenticated actor, or a non-author without Manage,
     * 403s.
     */
    private function authorOrManageActor(Request $request, Node $node, int|string $commentId): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            throw PermissionDeniedException::forAbility(Ability::Manage->value);
        }

        $comment = $this->findComment($node, $commentId);

        if ($this->isAuthor($actor, $comment) || $this->access->can($actor, Ability::Manage, $node)) {
            return;
        }

        throw PermissionDeniedException::forAbility(Ability::Manage->value);
    }

    private function findComment(Node $node, int|string $commentId): NodeComment
    {
        $comment = $this->comments->find($commentId);

        if ($comment === null || Identity::stringify($comment->node_id) !== Identity::stringify($node->getKey())) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }

    private function isAuthor(Authorizable $actor, NodeComment $comment): bool
    {
        if (!$actor instanceof Model) {
            return false;
        }

        return $actor->getMorphClass() === $comment->author_type
            && Identity::stringify($actor->getKey()) === Identity::stringify($comment->author_id);
    }

    private function guardAbility(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
