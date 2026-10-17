<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Search;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Http\Requests\Search\SearchNodesRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\ListQuery;

/**
 * Nodes of a drive whose name matches `q`, limited to what the caller can see — a
 * caller granted a folder deep inside finds what is in it without seeing the root.
 */
#[Internal]
final readonly class SearchNodesController
{
    public function __construct(private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(SearchNodesRequest $request, string $drive): JsonResponse
    {
        $options = ListQuery::options($request);

        return $this->contexts->onVisibleDrive($request, $drive, fn (DriveHandle $handle): JsonResponse => $this->nodes->page(
            $handle,
            $handle->nodes()->search($request->term(), new SearchOptions(perPage: $options->perPage, cursor: $options->cursor)),
            $options->perPage,
        ));
    }
}
