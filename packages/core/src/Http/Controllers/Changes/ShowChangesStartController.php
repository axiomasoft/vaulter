<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Changes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Http\Support\HttpOperationContextFactory;

/** The cursor to follow the drive's changes from, taken after (not before) a full listing. */
#[Internal]
final readonly class ShowChangesStartController
{
    public function __construct(private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $cursor = $this->contexts->onVisibleDrive($request, $drive, static fn (DriveHandle $handle): string => $handle->changes()->startCursor());

        return new JsonResponse(['data' => ['cursor' => $cursor]]);
    }
}
