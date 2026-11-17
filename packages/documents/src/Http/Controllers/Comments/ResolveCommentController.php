<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\CommentPreconditions;
use Vaulter\Documents\Http\Resources\CommentResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Resolve a comment thread (Comment, and authorship or Edit); If-Match carries the comment's tag (428 without it, 412 stale_write when it changed since). */
#[Internal]
final readonly class ResolveCommentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents, private CommentPreconditions $preconditions) {}

    public function __invoke(Request $request, string $node, string $comment): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        $resource = new CommentResource($this->documents->comments($drive)->resolve($node, $comment, $this->preconditions->expectedVersion($request, $drive, $node, $comment, delete: false)));

        return $resource->response()->setEtag($resource->etag());
    }
}
