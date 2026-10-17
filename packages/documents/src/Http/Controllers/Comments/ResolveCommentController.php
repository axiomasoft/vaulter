<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\CommentResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Resolve a comment thread (Comment, and authorship or Edit). */
#[Internal]
final readonly class ResolveCommentController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node, string $comment): CommentResource
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        return new CommentResource($this->documents->comments($drive)->resolve($node, $comment));
    }
}
