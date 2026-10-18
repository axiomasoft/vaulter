<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Http\Resources\Access\GrantResource;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * The grants set directly on a node (Manage), tagged with the node's access version: the `etag` a revoke or an
 * inheritance change sends back as If-Match. A list is never stored, so the tag is in the body only (no ETag header).
 * The version is read before the grants, so the tag is never newer than the list it comes with (a change in between
 * makes a write with it stale, never blind).
 */
#[Internal]
final readonly class ListGrantsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $etag = AggregatePreconditions::accessTag($drive->nodes()->find($node));
        $grants = $drive->access()->grants($node);

        return new JsonResponse(['etag' => $etag, 'data' => array_map(static fn (GrantSnapshot $grant): GrantResource => new GrantResource($grant), $grants)]);
    }
}
