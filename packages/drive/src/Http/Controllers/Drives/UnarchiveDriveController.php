<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\Request;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Http\Support\HttpOperationContextFactory;

/** Make an archived drive writable again (Manage on its root). */
final readonly class UnarchiveDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): DriveResource
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle): DriveResource {
            $root = $handle->root();
            $snapshot = $handle->unarchive();

            return new DriveResource($snapshot, $handle->quota(), $root->id);
        });
    }
}
