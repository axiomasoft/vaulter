<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\GrantPermissionAction;
use DocVault\Actions\RevokePermissionAction;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\StorePermissionRequest;
use DocVault\Http\Resources\DocumentPermissionResource;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\Repositories\PermissionReadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PermissionController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly PermissionReadRepository $permissions,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request, int|string $documentId): AnonymousResourceCollection
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        return DocumentPermissionResource::collection($this->permissions->forDocument($document)->get());
    }

    public function store(StorePermissionRequest $request, int|string $documentId, GrantPermissionAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        return DocumentPermissionResource::make($action->execute($document, $request->toData()))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        int|string $documentId,
        int|string $permissionId,
        RevokePermissionAction $action,
    ): Response {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        $permission = $this->permissions->forDocument($document)->find($permissionId);

        if ($permission === null) {
            throw new NotFoundHttpException('Permission not found.');
        }

        $action->execute($document, $permission->subject_type, $permission->subject_id, $this->actorId($request));

        return response()->noContent();
    }

    private function authorizeDocument(Request $request, string $action, Document $document): void
    {
        if (!$this->visibility->can($request->user(), $action, $document)) {
            throw PermissionDeniedException::forAction($action);
        }
    }
}
