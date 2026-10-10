<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\JsonResponse;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Http\Requests\Files\AddFileRevisionRequest;
use Vaulter\Drive\Http\Resources\FileRevisionResource;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Enums\Access\Ability;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Upload the next revision of a file (Edit); If-Match is required. */
final readonly class AddFileRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(AddFileRevisionRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequence($request, $drive, $node, Ability::Edit);
        $revision = $drive->files()->addRevision($node, UploadSource::fromUploadedFile($request->uploadedFile()), new RevisionOptions(expectedSequence: $expected));

        return (new FileRevisionResource($revision))->response()->setStatusCode(201);
    }
}
