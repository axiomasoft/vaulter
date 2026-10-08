<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\StoreNodeCommentRequest;
use Vaulter\Documents\Http\Resources\NodeCommentResource;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * The NODE-attached comment HTTP surface (ADR-0014 шаг 1). Thin: every endpoint calls
 * {@see CommentOperations} as the request's user.
 *
 * Authorization (decided by the operations):
 *   - index   → View (anyone who can see the node reads its thread);
 *   - store   → Comment;
 *   - resolve → Comment, and the author of the comment or Edit on the node;
 *   - destroy → the author of the comment or Manage on the node.
 * A node the user cannot see answers 404 node_not_found; a comment of another node
 * answers 404 comment_not_found.
 */
final class NodeCommentController extends Controller
{
    /** The thread is returned whole until REST v1 adds paging to this endpoint. */
    private const int PAGE = 200;

    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly DocumentsManager $documents,
    ) {}

    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $comments = $this->operations($request, $node);
        $thread = [];
        $cursor = null;
        do {
            $page = $comments->list($this->key($node), new ListOptions(perPage: self::PAGE, cursor: $cursor));
            array_push($thread, ...$page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return NodeCommentResource::collection($thread);
    }

    public function store(StoreNodeCommentRequest $request, Node $node): JsonResponse
    {
        $comment = $this->operations($request, $node)->add($this->key($node), $request->toData());

        return NodeCommentResource::make($comment)->response()->setStatusCode(201);
    }

    public function resolve(Request $request, Node $node, int|string $comment): NodeCommentResource
    {
        return NodeCommentResource::make($this->operations($request, $node)->resolve($this->key($node), Identity::stringify($comment)));
    }

    public function destroy(Request $request, Node $node, int|string $comment): Response
    {
        $this->operations($request, $node)->delete($this->key($node), Identity::stringify($comment));

        return response()->noContent();
    }

    private function operations(Request $request, Node $node): CommentOperations
    {
        $user = $request->user();
        if (!$user instanceof Authorizable) {
            throw MissingActorException::required();
        }
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId(Identity::stringify($node->getKey()));

        return $this->documents->comments($this->vaulter->drive(ownerOrDrive: $drive)->actingAs($user));
    }

    private function key(Node $node): string
    {
        return Identity::stringify($node->getKey());
    }
}
