<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Actions\IssueCollaborationTokenAction;
use Vaulter\Actions\SyncCollaborationStateAction;
use Vaulter\Exceptions\PermissionDeniedException;
use Vaulter\Http\Requests\SyncCollaborationRequest;
use Vaulter\Http\Resources\DocumentResource;
use Vaulter\Repositories\DocumentReadRepository;
use Vaulter\Repositories\DocumentVisibilityService;

final class CollaborationController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function token(Request $request, int|string $documentId, IssueCollaborationTokenAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $user = $request->user() ?? abort(401);

        return new JsonResponse($action->execute($document, $user));
    }

    public function sync(
        SyncCollaborationRequest $request,
        int|string $documentId,
        SyncCollaborationStateAction $action,
    ): DocumentResource {
        $document = $this->documents->findOrFail($documentId);

        if (!$this->visibility->can($request->user(), 'edit', $document)) {
            throw PermissionDeniedException::forAction('edit');
        }

        return DocumentResource::make($action->execute($document, $request->content(), $this->actorId($request)));
    }
}
