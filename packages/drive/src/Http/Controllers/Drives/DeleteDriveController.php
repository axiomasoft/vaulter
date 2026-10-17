<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Drives;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Resources\DriveResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/**
 * Start deleting a drive (Manage on its root). The drive is gone for everyone at once
 * and is purged in the background, so the answer is its last state, `deleting`, with the
 * root and quota read before the drive disappeared.
 */
#[Internal]
final readonly class DeleteDriveController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): DriveResource
    {
        return $this->contexts->onDrive($request, $drive, static function (DriveHandle $handle): DriveResource {
            $root = $handle->root();
            $quota = $handle->quota();

            return new DriveResource($handle->delete(), $quota, $root->id);
        });
    }
}
