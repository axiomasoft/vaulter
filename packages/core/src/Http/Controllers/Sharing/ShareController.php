<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Access\AccessResolver;
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Sharing\StoreShareLinkRequest;
use Vaulter\Http\Resources\Sharing\NodeShareResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Support\Identity;

/**
 * The authenticated OWNER surface for bearer share-links (§2.7 / M2c): list, create
 * and revoke shares on a node. Every action requires Ability::Share on the node —
 * the AccessResolver enforces it (a denial 403s through PermissionDeniedException),
 * so this is the identity-ACL channel governing who may MINT a public link. The
 * public, token-resolved channel lives in {@see PublicShareController}.
 *
 * Thin — writes delegate to the Create/Revoke Actions, reads to the
 * NodeShareReadRepository. create() returns the raw token exactly once; index()
 * never exposes it (the listing carries prefix/hash-free metadata only).
 */
final class ShareController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly NodeShareReadRepository $shares,
        private readonly CreateShareLinkAction $create,
        private readonly RevokeShareLinkAction $revoke,
    ) {}

    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardShare($request, $node);

        $shares = $this->shares
            ->forNode($node)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeShareResource::collection($shares);
    }

    public function store(StoreShareLinkRequest $request, Node $node): JsonResponse
    {
        $this->guardShare($request, $node);

        $minted = $this->create->execute(
            node: $node,
            data: $request->toData(grantedBy: $this->actorId($request)),
        );

        // The raw token is attached here and ONLY here — returned exactly once.
        return NodeShareResource::make($minted->share)
            ->withToken($minted->token)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Node $node, int|string $share): Response
    {
        $this->guardShare($request, $node);

        $model = $this->shares->find($share);

        // The share must exist AND belong to the authorized node — a foreign share
        // id can never be revoked through this node (and a 404 leaks nothing).
        if (!$model instanceof NodeShare || Identity::stringify($model->node_id) !== Identity::stringify($node->getKey())) {
            throw new NotFoundHttpException('Share link not found.');
        }

        $this->revoke->execute(share: $model, actorId: $this->actorId($request));

        return response()->noContent();
    }

    private function guardShare(Request $request, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, Ability::Share, $node)) {
            throw PermissionDeniedException::forAbility(Ability::Share->value);
        }
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }
}
