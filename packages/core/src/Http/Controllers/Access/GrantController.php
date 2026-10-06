<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Access\DestroyNodeGrantRequest;
use Vaulter\Http\Requests\Access\StoreNodeGrantRequest;
use Vaulter\Http\Resources\Drive\NodeGrantResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\OperationContext;
use Vaulter\ValueObjects\Access\Actor;

final class GrantController extends Controller
{
    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context($request, $node)]);

        return NodeGrantResource::collection($operations->paginate(node: $node, perPage: max(1, min($request->integer('per_page', 50), 200)))->withQueryString());
    }

    public function store(StoreNodeGrantRequest $request, Node $node): JsonResponse
    {
        $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context($request, $node)]);
        $grant = $operations->grant(node: $node, grant: $request->toData());

        return NodeGrantResource::make($grant)->response()->setStatusCode(201);
    }

    public function destroy(DestroyNodeGrantRequest $request, Node $node): Response
    {
        $operations = app()->makeWith(AccessOperations::class, ['context' => $this->context($request, $node)]);
        // The existing HTTP command revokes all scopes/effects for this subject.
        foreach ($operations->grants($node) as $grant) {
            if ($grant->subject_type === $request->string('subject_type')->toString() && (string) $grant->subject_id === (string) $this->scalarId($request, 'subject_id')) {
                $operations->revoke(node: $node, grant: $grant);
            }
        }

        return response()->noContent();
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
