<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\CreateDocumentRequest;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Support\HttpOperationContextFactory;

/** Create a document under a folder (Create in the parent; the drive root when no parent is given). */
#[Internal]
final readonly class CreateDocumentController
{
    public function __construct(private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(CreateDocumentRequest $request, string $drive): JsonResponse
    {
        $create = function (DriveHandle $handle) use ($request): JsonResponse {
            $node = $this->documents->in($handle)->create(
                name: $request->name(),
                type: $request->typeKey(),
                content: $request->content(),
                parent: $request->parentId(),
            );
            $resource = new NodeResource($node, $handle->nodes()->abilitiesMany([$node])[$node->id] ?? NodeAbilities::denied());

            return $resource->response()->setStatusCode(201)->setEtag($resource->etag());
        };

        return $this->contexts->withinDrive($request, $drive, $create);
    }
}
