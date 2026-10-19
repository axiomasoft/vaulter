<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\AddCommentRequest;
use Vaulter\Documents\Http\Resources\CommentResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Add a comment, or a reply to one of the node's comments (Comment). */
#[Internal]
final readonly class AddCommentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(AddCommentRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        $resource = new CommentResource($this->documents->comments($drive)->add($node, $request->toData()));

        return $resource->response()->setStatusCode(201)->setEtag($resource->etag());
    }
}
