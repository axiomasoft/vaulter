<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\UpdateDocumentContentRequest;
use Vaulter\Documents\Http\Resources\DocumentResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Http\Support\Preconditions;

/**
 * Replace a document's content (Edit). If-Match is compared under the same lock
 * that checks write mode and saves the content.
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

        return $this->responses->content($this->documents->in($drive)->updateContent(
            $node, $request->content(), matchesTag: static fn (string $tag): bool => Preconditions::matches($request, $tag),
        ));
    }
}
