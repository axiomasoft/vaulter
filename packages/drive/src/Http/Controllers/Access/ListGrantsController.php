<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Http\Resources\Access\GrantResource;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Operations\AccessOperations;
use Vaulter\Http\Resources\PageResponse;

/**
 * One bounded page (default 50, max 200) of the grants set directly on a node (Manage), tagged with the node's access
 * version: the top-level `etag` a revoke or an inheritance change sends back as If-Match. A list is never stored, so
 * the tag is in the body only (no ETag header). The version is read before the grants, so the tag is never newer than
 * the page it comes with (a change in between makes a write with it stale, never blind). Every page carries the tag
 * of its own read; a client that pages through must re-read from the first page when the tags differ.
 */
#[Internal]
final readonly class ListGrantsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $perPage = PageResponse::perPage($request);
        $cursor = PageResponse::cursor($request);
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $etag = AggregatePreconditions::accessTag($drive->nodes()->find($node));
        $access = $drive->access();
        if (!$access instanceof AccessOperations) {
            throw new LogicException('The drive access operations must be the drive package implementation.');
        }
        $response = PageResponse::make($access->paginate($node, $perPage, $cursor), static fn (GrantSnapshot $grant): GrantResource => new GrantResource($grant), $perPage);

        return $response->setData(['etag' => $etag] + (array) $response->getData(true));
    }
}
