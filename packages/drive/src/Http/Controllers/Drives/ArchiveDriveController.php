<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/** Make the drive read-only (Manage on its root). */
#[Internal]
final readonly class ArchiveDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): DriveResource
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle): DriveResource {
            $root = $handle->root();
            $snapshot = $handle->archive();

            return new DriveResource($snapshot, $handle->quota(), $root->id);
        });
    }
}
