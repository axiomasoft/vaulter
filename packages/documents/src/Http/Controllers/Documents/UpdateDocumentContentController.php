<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\UpdateDocumentContentRequest;
use Vaulter\Documents\Http\Resources\DocumentResponses;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * Replace a document's content (Edit). If-Match is required and is compared with the
 * content's own tag, so renaming or moving the document never invalidates it; a hub
 * store that landed after the caller read makes this write fail 412 document_state_mismatch.
 */
#[Internal]
final readonly class UpdateDocumentContentController
{
    public function __construct(
        private HttpResourceResolver $resolver,
        private HttpOperationContextFactory $contexts,
        private DocumentsManager $documents,
        private DocumentResponses $responses,
    ) {}

    public function __invoke(UpdateDocumentContentRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->responses->expectedStateVersion($request, $drive, $node);

        return $this->responses->content($this->documents->in($drive)->update($node, $request->content(), $expected));
    }
}
