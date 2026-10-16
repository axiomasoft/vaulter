<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;

/** The caller's starred nodes of a drive that it can still see, newest star first. */
#[Internal]
final readonly class ListStarredController
{
    public function __construct(private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $options = PageResponse::listOptions($request);

        return $this->contexts->onVisibleDrive($request, $drive, fn (DriveHandle $handle): JsonResponse => $this->nodes->page($handle, $handle->nodes()->starred($options), $options->perPage));
    }
}
