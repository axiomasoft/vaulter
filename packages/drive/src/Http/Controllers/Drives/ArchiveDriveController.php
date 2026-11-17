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
 * Make the drive read-only (Manage on its root). If-Match carries the
 * drive's tag (428 without it, 412 stale_write when it changed since).
 */
#[Internal]
final readonly class ArchiveDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        return $this->contexts->onDrive($request, $drive, function (DriveHandle $handle) use ($request): JsonResponse {
            $root = $handle->root();
            $snapshot = $handle->archive($this->preconditions->expectedDriveVersion($request, $handle));
            $resource = new DriveResource($snapshot, $handle->quota(), $root->id);

            return $resource->response()->setEtag($resource->etag());
        });
    }
}
