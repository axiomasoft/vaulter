<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Revisions;

use Illuminate\Http\Request;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** One checkpoint with its content (View). */
final readonly class ShowDocumentRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node, string $revision): DocumentRevisionResource
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $document = $this->documents->in($drive)->content($node, $revision);

        return new DocumentRevisionResource($document->revision ?? throw DocumentRevisionNotFoundException::forNode($node, $revision), $document->content);
    }
}
