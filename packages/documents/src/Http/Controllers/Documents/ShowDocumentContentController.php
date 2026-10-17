<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\DocumentContentResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Http\Support\Preconditions;

/**
 * A document's working state with its tag in the ETag header. A matching If-None-Match
 * is answered 304 only after the document was found as this caller, so a caller who lost
 * access gets 404, never a cached "not modified". The body does not depend on the caller
 * (a reader and an editor get the same one).
 */
#[Internal]
final readonly class ShowDocumentContentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node): JsonResponse|Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $resource = new DocumentContentResource($this->documents->in($drive)->content($node));

        if (Preconditions::notModified($request, $resource->etag())) {
            return (new Response(status: 304))->setEtag($resource->etag());
        }

        return $resource->response()->setEtag($resource->etag());
    }
}
