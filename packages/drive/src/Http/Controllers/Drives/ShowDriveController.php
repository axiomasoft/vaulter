<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/** A drive's meta and quota, for a caller who can see its root folder; `etag` is the drive's tag. */
#[Internal]
final readonly class ShowDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle): JsonResponse {
            // root() authorizes View on the root folder before any meta is read.
            $root = $handle->root();

            // The tag is in the body only: a drive (its quota moves without its version) is never stored.
            return new DriveResource($handle->snapshot(), $handle->quota(), $root->id)->response();
        });
    }
}
