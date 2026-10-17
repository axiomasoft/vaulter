<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Http\Middleware\ResolveShareToken;
use Vaulter\Drive\Http\Resources\Sharing\SharedNodeResource;

/**
 * The node behind a share link. Public routes authorize only by the link's own role on
 * its own node-set (resolved by ResolveShareToken); identity grants play no part.
 */
#[Internal]
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
