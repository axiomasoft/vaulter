<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Trash;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;

/** Purge every trash root of the drive the caller may delete; the others stay. */
#[Internal]
final readonly class EmptyTrashController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $purged = $this->contexts->onVisibleDrive($request, $drive, static fn (DriveHandle $handle): int => $handle->trash()->empty());

        return new JsonResponse(['data' => ['purged_count' => $purged]]);
    }
}
