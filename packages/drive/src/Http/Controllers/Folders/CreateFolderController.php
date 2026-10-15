<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Folders;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Http\Requests\Folders\CreateFolderRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;

/** Create a folder (Create in the parent; the drive root when no parent is given). */
#[Internal]
final readonly class CreateFolderController
{
    public function __construct(private NodeResponses $nodes) {}

    public function __invoke(CreateFolderRequest $request, string $drive): JsonResponse
    {
        $parent = $request->parentId();
        $create = fn (DriveHandle $handle): JsonResponse => $this->nodes->respond($handle, $handle->folders()->create(name: $request->name(), parent: $parent), 201);

        return $this->nodes->withinDrive($request, $drive, $create);
    }
}
