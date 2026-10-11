<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Delete a comment (its author, or Manage on the node). */
final readonly class DeleteCommentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node, string $comment): Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $this->documents->comments($drive)->delete($node, $comment);

        return new Response(status: 204);
    }
}
