<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Revisions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Http\Resources\PageResponse;

/** The revision history of a document, newest first (View). */
#[Internal]
final readonly class ListDocumentRevisionsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $page = $this->documents->in($drive)->revisions($node, $options);

        return PageResponse::make($page, static fn (DocumentRevisionSnapshot $revision): DocumentRevisionResource => new DocumentRevisionResource($revision), $options->perPage);
    }
}
