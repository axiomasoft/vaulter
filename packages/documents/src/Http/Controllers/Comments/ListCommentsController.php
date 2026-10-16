<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Comments;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Resources\CommentResource;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** The comments on a node, oldest first (View). */
#[Internal]
final readonly class ListCommentsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private DocumentsManager $documents) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $page = $this->documents->comments($drive)->list($node, $options);

        return PageResponse::make($page, static fn (CommentSnapshot $comment): CommentResource => new CommentResource($comment), $options->perPage);
    }
}
