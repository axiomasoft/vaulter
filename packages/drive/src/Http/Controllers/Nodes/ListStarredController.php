<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\ListQuery;

/** The caller's starred nodes of a drive that it can still see, newest star first. */
#[Internal]
final readonly class ListStarredController
{
    public function __construct(private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $options = ListQuery::options($request);

        return $this->contexts->onVisibleDrive($request, $drive, fn (DriveHandle $handle): JsonResponse => $this->nodes->page($handle, $handle->nodes()->starred($options), $options->perPage));
    }
}
