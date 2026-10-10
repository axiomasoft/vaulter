<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Http\Requests\Nodes\ListChildrenRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Http\Resources\PageResponse;

/**
 * The visible children of a folder, one cursor page at a time; without `parent` the
 * drive root's. A miss under a drive whose root the caller cannot see is
 * drive_not_found, like an unknown drive; a missing folder in a visible drive is
 * node_not_found.
 */
final readonly class ListChildrenController
{
    public function __construct(private NodeResponses $nodes) {}

    public function __invoke(ListChildrenRequest $request, string $drive): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $parent = $request->parent();
        $list = fn (DriveHandle $handle): JsonResponse => $this->nodes->page($handle, $handle->nodes()->children($parent, $options), $options->perPage);

        return $this->nodes->withinDrive($request, $drive, $list);
    }
}
