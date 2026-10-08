<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Sharing\StoreShareLinkRequest;
use Vaulter\Http\Requests\Sharing\UpdateShareLinkRequest;
use Vaulter\Http\Resources\Sharing\NodeShareResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\Actor;

final class ShareController extends Controller
{
    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $operations = $this->operations($request, $node);

        return NodeShareResource::collection($operations->paginate(node: Identity::stringify($node->getKey()), perPage: max(1, min($request->integer('per_page', 50), 200)))->withQueryString());
    }

    public function store(StoreShareLinkRequest $request, Node $node): JsonResponse
    {
        $operations = $this->operations($request, $node);
        $minted = $operations->create(node: Identity::stringify($node->getKey()), input: $request->toData());

        return NodeShareResource::make($minted->link)->withToken($minted->token)->response()->setStatusCode(201);
    }

    public function update(UpdateShareLinkRequest $request, Node $node, int|string $share): JsonResponse
    {
        $operations = $this->operations($request, $node);

        return NodeShareResource::make($operations->update(node: Identity::stringify($node->getKey()), share: $share, changes: $request->toData()))->response();
    }

    public function rotate(Request $request, Node $node, int|string $share): JsonResponse
    {
        $operations = $this->operations($request, $node);
        $minted = $operations->rotate(node: Identity::stringify($node->getKey()), share: $share);

        return NodeShareResource::make($minted->link)->withToken($minted->token)->response();
    }

    public function destroy(Request $request, Node $node, int|string $share): Response
    {
        $operations = $this->operations($request, $node);
        $operations->revoke(node: Identity::stringify($node->getKey()), share: $share);

        return response()->noContent();
    }

    private function operations(Request $request, Node $node): ShareOperations
    {
        return app()->makeWith(ShareOperations::class, ['context' => $this->context($request, $node)]);
    }

    private function context(Request $request, Node $node): OperationContext
    {
        $user = $request->user();
        if (!$user instanceof Authorizable) {
            throw MissingActorException::required();
        }
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return new OperationContext(drive: $drive, actor: Actor::user($user), correlationId: (string) Str::ulid());
    }
}
