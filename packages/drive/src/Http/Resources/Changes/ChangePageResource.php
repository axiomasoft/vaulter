<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Changes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Data\Changes\ChangePage;
use Vaulter\Drive\Data\Changes\NodeChange;

/**
 * A page of the change journal. Unlike listings it always returns a cursor: the one to
 * store and continue from, now if has_more, later otherwise.
 */
#[Internal]
final class ChangePageResource
{
    /**
     * @param array<string, NodeAbilities> $abilities node id => the reader's abilities, for the changes that carry a node
     */
    public static function response(Request $request, ChangePage $page, array $abilities): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                static fn (NodeChange $change): array => new ChangeResource($change, $change->nodeId === null ? null : ($abilities[$change->nodeId] ?? null))->toArray($request),
                $page->changes,
            ),
            'meta' => ['next_cursor' => $page->cursor, 'has_more' => $page->hasMore],
        ]);
    }
}
