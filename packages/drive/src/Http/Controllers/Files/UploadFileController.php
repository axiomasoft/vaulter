<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;

/** Upload a new file in one request (Create in the parent; the drive root when no parent is given). */
#[Internal]
final readonly class UploadFileController
{
    public function __construct(private NodeResponses $nodes) {}

    public function __invoke(UploadFileRequest $request, string $drive): JsonResponse
    {
        $parent = $request->parentId();
        $upload = fn (DriveHandle $handle): JsonResponse => $this->nodes->respond($handle, $handle->files()->upload(
            UploadSource::fromUploadedFile($request->uploadedFile()),
            new UploadOptions(parent: $parent, name: $request->name()),
        ), 201);

        return $this->nodes->withinDrive($request, $drive, $upload);
    }
}
