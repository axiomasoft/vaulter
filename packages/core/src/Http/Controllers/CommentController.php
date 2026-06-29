<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Actions\AddCommentAction;
use Vaulter\Actions\DeleteCommentAction;
use Vaulter\Actions\ResolveCommentAction;
use Vaulter\Exceptions\PermissionDeniedException;
use Vaulter\Http\Requests\StoreCommentRequest;
use Vaulter\Http\Resources\DocumentCommentResource;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentComment;
use Vaulter\Repositories\CommentReadRepository;
use Vaulter\Repositories\DocumentReadRepository;
use Vaulter\Repositories\DocumentVisibilityService;

final class CommentController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly CommentReadRepository $comments,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request, int|string $documentId): AnonymousResourceCollection
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'view', $document);

        return DocumentCommentResource::collection($this->comments->forDocument($document)->get());
    }

    public function store(StoreCommentRequest $request, int|string $documentId, AddCommentAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'comment', $document);

        return DocumentCommentResource::make($action->execute($document, $request->toData()))
            ->response()
            ->setStatusCode(201);
    }

    public function resolve(
        Request $request,
        int|string $documentId,
        int|string $commentId,
        ResolveCommentAction $action,
    ): DocumentCommentResource {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'comment', $document);

        $comment = $this->findComment($document, $commentId);

        return DocumentCommentResource::make($action->execute($comment, $this->actorId($request)));
    }

    public function destroy(
        Request $request,
        int|string $documentId,
        int|string $commentId,
        DeleteCommentAction $action,
    ): Response {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        $action->execute($this->findComment($document, $commentId));

        return response()->noContent();
    }

    private function findComment(Document $document, int|string $commentId): DocumentComment
    {
        $comment = $this->comments->forDocument($document)->find($commentId);

        if ($comment === null) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }

    private function authorizeDocument(Request $request, string $action, Document $document): void
    {
        if (!$this->visibility->can($request->user(), $action, $document)) {
            throw PermissionDeniedException::forAction($action);
        }
    }
}
