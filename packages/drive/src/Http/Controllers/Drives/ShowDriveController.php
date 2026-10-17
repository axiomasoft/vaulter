<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/** A drive's meta and quota, for a caller who can see its root folder. */
#[Internal]
final readonly class ShowDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): DriveResource
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle): DriveResource {
            // root() authorizes View on the root folder before any meta is read.
            $root = $handle->root();

            return new DriveResource($handle->snapshot(), $handle->quota(), $root->id);
        });
    }
}
