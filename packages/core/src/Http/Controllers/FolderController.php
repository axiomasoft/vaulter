<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use DocVault\Actions\CreateFolderAction;
use DocVault\Actions\DeleteFolderAction;
use DocVault\Actions\MoveFolderAction;
use DocVault\Actions\RenameFolderAction;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Http\Requests\StoreFolderRequest;
use DocVault\Http\Resources\DocumentFolderResource;
use DocVault\Models\DocumentFolder;
use DocVault\Repositories\DocumentVisibilityService;
use DocVault\Repositories\FolderReadRepository;
use DocVault\ValueObjects\WorkspaceRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FolderController extends Controller
{
    public function __construct(
        private readonly FolderReadRepository $folders,
        private readonly DocumentVisibilityService $visibility,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = new WorkspaceRef(
            type: $request->string('workspace_type')->toString(),
            id: $this->scalarId($request, 'workspace_id'),
        );

        return DocumentFolderResource::collection($this->folders->forWorkspace($workspace)->get());
    }

    public function store(StoreFolderRequest $request, CreateFolderAction $action): JsonResponse
    {
        $data = $request->toData();
        $this->authorizeWorkspace($request, $data->workspaceType, $data->workspaceId);

        return DocumentFolderResource::make($action->execute($data))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int|string $id, RenameFolderAction $rename, MoveFolderAction $move): DocumentFolderResource
    {
        $folder = $this->findFolder($id);
        $this->authorizeWorkspace($request, $folder->workspace_type, $folder->workspace_id);

        if ($request->has('name')) {
            $folder = $rename->execute($folder, $request->string('name')->toString());
        }

        if ($request->has('parent_id')) {
            $folder = $move->execute($folder, $this->nullableParent($request));
        }

        return DocumentFolderResource::make($folder);
    }

    public function destroy(Request $request, int|string $id, DeleteFolderAction $action): Response
    {
        $folder = $this->findFolder($id);
        $this->authorizeWorkspace($request, $folder->workspace_type, $folder->workspace_id);

        $action->execute($folder);

        return response()->noContent();
    }

    private function findFolder(int|string $id): DocumentFolder
    {
        return $this->folders->find($id) ?? throw new NotFoundHttpException('Folder not found.');
    }

    private function nullableParent(Request $request): int|string|null
    {
        $value = $request->input('parent_id');

        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    private function authorizeWorkspace(Request $request, string $workspaceType, int|string $workspaceId): void
    {
        if (!$this->visibility->canCreate($request->user(), $workspaceType, $workspaceId)) {
            throw PermissionDeniedException::forAction('manage-folders');
        }
    }
}
