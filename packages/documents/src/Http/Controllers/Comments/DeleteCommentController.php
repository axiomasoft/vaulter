<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packman\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\CommentPreconditions;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Delete a comment (its author, or Manage on the node); If-Match carries the comment's tag (428 without it, 412 stale_write when it changed since). */
#[Internal]
final readonly class DeleteCommentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents, private CommentPreconditions $preconditions) {}

    public function __invoke(Request $request, string $node, string $comment): Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $this->documents->comments($drive)->delete($node, $comment, $this->preconditions->expectedVersion($request, $drive, $node, $comment, delete: true));

        return new Response(status: 204);
    }
}
