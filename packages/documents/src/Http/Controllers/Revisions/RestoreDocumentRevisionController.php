<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Revisions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\DocumentResponses;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * Copy a checkpoint forward as the new working state (Edit). The checkpoint itself is
 * never changed and open collaboration rooms move to a new epoch, so If-Match is required
 * and is compared with the content's current tag.
 */
final readonly class RestoreDocumentRevisionController
{
    public function __construct(
        private HttpResourceResolver $resolver,
        private HttpOperationContextFactory $contexts,
        private DocumentsManager $documents,
        private DocumentResponses $responses,
    ) {}

    public function __invoke(Request $request, string $node, string $revision): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->responses->expectedStateVersion($request, $drive, $node);

        return $this->responses->content($this->documents->in($drive)->restore($node, $revision, $expected));
    }
}
