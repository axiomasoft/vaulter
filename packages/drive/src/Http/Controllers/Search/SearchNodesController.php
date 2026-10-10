<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Search;

use Illuminate\Http\JsonResponse;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Drive\Http\Requests\Search\SearchNodesRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;

/**
 * Nodes of a drive whose name matches `q`, limited to what the caller can see — a
 * caller granted a folder deep inside finds what is in it without seeing the root.
 */
final readonly class SearchNodesController
{
    public function __construct(private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(SearchNodesRequest $request, string $drive): JsonResponse
    {
        $options = PageResponse::listOptions($request);

        return $this->contexts->onDrive($request, $drive, fn (DriveHandle $handle): JsonResponse => $this->nodes->page(
            $handle,
            $handle->nodes()->search($request->term(), new SearchOptions(perPage: $options->perPage, cursor: $options->cursor)),
            $options->perPage,
        ));
    }
}
