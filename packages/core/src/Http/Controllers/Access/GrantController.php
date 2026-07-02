<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Access\DestroyNodeGrantRequest;
use Vaulter\Http\Requests\Access\StoreNodeGrantRequest;
use Vaulter\Http\Resources\Drive\NodeGrantResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantReadRepository;

/**
 * The per-node ACL surface (§2.6 / H.2): list, grant and revoke node grants. Every
 * action requires the Share ability on the node (only someone who may share a node
 * may change its grants); the AccessResolver enforces it, a denial 403s through
 * the existing PermissionDeniedException. Writes delegate to the Grant/Revoke
 * Actions; reads to the GrantReadRepository.
 */
final class GrantController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly GrantReadRepository $grants,
        private readonly GrantNodeAccessAction $grant,
        private readonly RevokeNodeAccessAction $revoke,
    ) {}

    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardShare($request, $node);

        $grants = $this->grants
            ->forNode($node)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeGrantResource::collection($grants);
    }

    public function store(StoreNodeGrantRequest $request, Node $node): JsonResponse
    {
        $this->guardShare($request, $node);

        $grant = $this->grant->execute(
            node: $node,
            data: $request->toData(grantedBy: $this->actorId($request)),
        );

        return NodeGrantResource::make($grant)->response()->setStatusCode(201);
    }

    public function destroy(DestroyNodeGrantRequest $request, Node $node): Response
    {
        $this->guardShare($request, $node);

        $this->revoke->execute(
            node: $node,
            subject: new Subject(
                type: $request->string('subject_type')->toString(),
                id: $this->scalarId($request, 'subject_id'),
            ),
            actorId: $this->actorId($request),
        );

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
