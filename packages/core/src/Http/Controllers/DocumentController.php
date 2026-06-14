<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\CreateDocumentAction;
use DocVault\Actions\DeleteDocumentAction;
use DocVault\Actions\DuplicateDocumentAction;
use DocVault\Actions\ForceDeleteDocumentAction;
use DocVault\Actions\MoveDocumentAction;
use DocVault\Actions\RestoreDocumentAction;
use DocVault\Actions\UpdateDocumentAction;
use DocVault\Exceptions\DocumentNotFoundException;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\MoveDocumentRequest;
use DocVault\Http\Requests\StoreDocumentRequest;
use DocVault\Http\Requests\UpdateDocumentRequest;
use DocVault\Http\Resources\DocumentResource;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentReadRepository;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\ValueObjects\WorkspaceRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentReadRepository $documents,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $this->workspaceFrom($request);

        $query = $request->filled('q')
            ? $this->documents->search($workspace, $request->string('q')->toString())
            : $this->documents->forWorkspace($workspace);

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->input('folder_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        $documents = $this->visibility->filterViewable($request->user(), $query->get());

        return DocumentResource::collection($documents);
    }

    public function trash(Request $request): AnonymousResourceCollection
    {
        $workspace = $this->workspaceFrom($request);

        $documents = $this->visibility->filterViewable(
            $request->user(),
            $this->documents->trashedForWorkspace($workspace)->get(),
        );

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request, CreateDocumentAction $action): JsonResponse
    {
        $data = $request->toData();

        if (!$this->visibility->canCreate($request->user(), $data->workspaceType, $data->workspaceId)) {
            throw PermissionDeniedException::forAction('create');
        }

        return DocumentResource::make($action->execute($data))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int|string $id): DocumentResource
    {
        $document = $this->documents->findOrFail($id);
        $this->authorizeDocument($request, 'view', $document);

        return DocumentResource::make($document);
    }

    public function update(UpdateDocumentRequest $request, int|string $id, UpdateDocumentAction $action): DocumentResource
    {
        $document = $this->documents->findOrFail($id);
        $this->authorizeDocument($request, 'edit', $document);

        return DocumentResource::make($action->execute($document, $request->toData()));
    }

    public function destroy(Request $request, int|string $id, DeleteDocumentAction $action): Response
    {
        $document = $this->documents->findOrFail($id);
        $this->authorizeDocument($request, 'delete', $document);

        $action->execute($document, $this->actorId($request));

        return response()->noContent();
    }

    public function forceDestroy(Request $request, int|string $id, ForceDeleteDocumentAction $action): Response
    {
        $document = $this->documents->findWithTrashed($id) ?? throw DocumentNotFoundException::withId($id);
        $this->authorizeDocument($request, 'manage', $document);

        $action->execute($document, $this->actorId($request));

        return response()->noContent();
    }

    public function restore(Request $request, int|string $id, RestoreDocumentAction $action): DocumentResource
    {
        $document = $this->documents->findWithTrashed($id) ?? throw DocumentNotFoundException::withId($id);
        $this->authorizeDocument($request, 'manage', $document);

        return DocumentResource::make($action->execute($document, $this->actorId($request)));
    }

    public function move(MoveDocumentRequest $request, int|string $id, MoveDocumentAction $action): DocumentResource
    {
        $document = $this->documents->findOrFail($id);
        $this->authorizeDocument($request, 'edit', $document);

        return DocumentResource::make($action->execute($document, $request->folderId(), $this->actorId($request)));
    }

    public function duplicate(Request $request, int|string $id, DuplicateDocumentAction $action): JsonResponse
    {
        $document = $this->documents->findOrFail($id);
        $this->authorizeDocument($request, 'view', $document);

        if (!$this->visibility->canCreate($request->user(), $document->workspace_type, $document->workspace_id)) {
            throw PermissionDeniedException::forAction('create');
        }

        return DocumentResource::make($action->execute($document, $this->actorId($request)))
            ->response()
            ->setStatusCode(201);
    }

    private function workspaceFrom(Request $request): WorkspaceRef
    {
        return new WorkspaceRef(
            type: $request->string('workspace_type')->toString(),
            id: $this->scalarId($request, 'workspace_id'),
        );
    }

    private function authorizeDocument(Request $request, string $action, Document $document): void
    {
        if (!$this->visibility->can($request->user(), $action, $document)) {
            throw PermissionDeniedException::forAction($action);
        }
    }
}
