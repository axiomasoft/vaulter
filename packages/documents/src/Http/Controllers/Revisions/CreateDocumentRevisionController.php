<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Revisions;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\CreateDocumentRevisionRequest;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Checkpoint the working state as a revision that becomes the head (Edit). */
#[Internal]
final readonly class CreateDocumentRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(CreateDocumentRevisionRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $revision = $this->documents->in($drive)->snapshot($node, label: $request->label(), keepForever: $request->keepForever());

        return (new DocumentRevisionResource($revision))->response()->setStatusCode(201);
    }
}
