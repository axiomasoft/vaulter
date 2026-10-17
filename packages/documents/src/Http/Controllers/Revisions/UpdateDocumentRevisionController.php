<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Revisions;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\UpdateDocumentRevisionRequest;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Keep a checkpoint out of pruning, or hand it back to the retention policy (Edit). */
#[Internal]
final readonly class UpdateDocumentRevisionController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(UpdateDocumentRevisionRequest $request, string $node, string $revision): DocumentRevisionResource
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        return new DocumentRevisionResource($this->documents->in($drive)->setRetention($node, $revision, $request->keepForever()));
    }
}
