<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Http\Resources\Sharing\SharedNodeResource;

/**
 * The node behind a share link. Public routes authorize only by the link's own role on
 * its own node-set (resolved by ResolveShareToken); identity grants play no part.
 */
final readonly class ShowPublicShareController
{
    public function __invoke(Request $request): JsonResponse
    {
        $context = ResolveShareToken::context($request);
        if (!$context->allows(Ability::View)) {
            throw ShareNotFoundException::opaque();
        }

        return SharedNodeResource::make($context->node)->response();
    }
}
