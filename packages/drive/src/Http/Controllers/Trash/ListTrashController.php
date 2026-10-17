<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Trash;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\ListQuery;

/** The trash roots of a drive the caller may view. Each item's etag is what restore and purge expect. */
#[Internal]
final readonly class ListTrashController
{
    public function __construct(private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $options = ListQuery::options($request);

        return $this->contexts->onVisibleDrive($request, $drive, fn (DriveHandle $handle): JsonResponse => $this->nodes->page($handle, $handle->trash()->list($options), $options->perPage));
    }
}
