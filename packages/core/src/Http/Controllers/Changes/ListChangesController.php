<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Changes;

use Illuminate\Http\JsonResponse;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Changes\NodeChange;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Http\Requests\Changes\ListChangesRequest;
use Vaulter\Http\Resources\Changes\ChangePageResource;
use Vaulter\Http\Support\HttpOperationContextFactory;

/**
 * The changes after a cursor, projected for the reader. The abilities of every node in
 * the page come from one batch evaluation.
 */
final readonly class ListChangesController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(ListChangesRequest $request, string $drive): JsonResponse
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle) use ($request): JsonResponse {
            $page = $handle->changes()->list($request->cursor(), $request->limit());
            $nodes = array_values(array_filter(array_map(static fn (NodeChange $change): ?NodeSnapshot => $change->node, $page->changes)));

            return ChangePageResource::response($request, $page, $nodes === [] ? [] : $handle->nodes()->abilitiesMany($nodes));
        });
    }
}
