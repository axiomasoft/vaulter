<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Files;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Http\Resources\FileRevisionResource;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** A file's retained revisions (View), newest first. */
#[Internal]
final readonly class ListFileRevisionsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        return PageResponse::make(
            $drive->files()->revisions($node, $options),
            static fn (FileRevisionSnapshot $revision): FileRevisionResource => new FileRevisionResource($revision),
            $options->perPage,
        );
    }
}
