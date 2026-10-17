<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Http\Support\SharedNodeResolver;
use Vaulter\Models\Drive\Node;

/** The children of a shared folder; an item link exposes one node and lists nothing. */
#[Internal]
final readonly class ListPublicShareNodesController
{
    public function __construct(private SharedNodeResolver $nodes) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = ResolveShareToken::context($request);
        if (!$context->allows(Ability::View) || $context->scope() !== GrantScope::Subtree) {
            throw ShareNotFoundException::opaque();
        }

        return PageResponse::fromPaginator(
            $this->nodes->children($context, PageResponse::cursor($request), PageResponse::perPage($request)),
            static fn (Node $node): SharedNodeResource => new SharedNodeResource($node),
        );
    }
}
