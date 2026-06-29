<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Actions\CreateVersionAction;
use Vaulter\Actions\RestoreVersionAction;
use Vaulter\Exceptions\PermissionDeniedException;
use Vaulter\Http\Requests\StoreVersionRequest;
use Vaulter\Http\Resources\DocumentResource;
use Vaulter\Http\Resources\DocumentVersionResource;
use Vaulter\Models\Document;
use Vaulter\Repositories\DocumentReadRepository;
use Vaulter\Repositories\DocumentVisibilityService;
use Vaulter\Repositories\VersionReadRepository;

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
