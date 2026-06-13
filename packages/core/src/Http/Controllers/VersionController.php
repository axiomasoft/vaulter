<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\CreateVersionAction;
use DocVault\Actions\RestoreVersionAction;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\StoreVersionRequest;
use DocVault\Http\Resources\DocumentResource;
use DocVault\Http\Resources\DocumentVersionResource;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\Repositories\VersionReadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VersionController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly VersionReadRepository $versions,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request, int|string $documentId): AnonymousResourceCollection
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'view', $document);

        return DocumentVersionResource::collection($this->versions->forDocument($document)->get());
    }

    public function store(StoreVersionRequest $request, int|string $documentId, CreateVersionAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'edit', $document);

        return DocumentVersionResource::make($action->execute($document, $request->toData()))
            ->response()
            ->setStatusCode(201);
    }

    public function restore(
        Request $request,
        int|string $documentId,
        int|string $versionId,
        RestoreVersionAction $action,
    ): DocumentResource {
        $document = $this->documents->findOrFail($documentId);
        $this->authorizeDocument($request, 'manage', $document);

        $version = $this->versions->find($versionId);

        if ($version === null) {
            throw new NotFoundHttpException('Version not found.');
        }

        return DocumentResource::make($action->execute($document, $version, $this->actorId($request)));
    }

    private function authorizeDocument(Request $request, string $action, Document $document): void
    {
        if (!$this->visibility->can($request->user(), $action, $document)) {
            throw PermissionDeniedException::forAction($action);
        }
    }
}
