<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/**
 * Start deleting a drive (Manage on its root). The drive is gone for everyone at once
 * and is purged in the background, so the answer is its last state, `deleting`, with the
 * root and quota read before the drive disappeared. If-Match carries the drive's tag
 * (428 without it, 412 stale_write when it changed since).
 */
#[Internal]
final readonly class DeleteDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        return $this->contexts->onDrive($request, $drive, function (DriveHandle $handle) use ($request): JsonResponse {
            $root = $handle->root();
            $quota = $handle->quota();
            $resource = new DriveResource($handle->delete($this->preconditions->expectedDriveVersion($request, $handle)), $quota, $root->id);

            return $resource->response()->setEtag($resource->etag());
        });
    }
}
