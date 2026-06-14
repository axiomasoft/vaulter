<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\IssueCollaborationTokenAction;
use DocVault\Repositories\DocumentReadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollaborationController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
    ) {}

    public function token(Request $request, int|string $documentId, IssueCollaborationTokenAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $user = $request->user() ?? abort(401);

        return new JsonResponse($action->execute($document, $user));
    }
}
