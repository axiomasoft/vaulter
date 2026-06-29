<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Actions\CreateFolderAction;
use Vaulter\Actions\DeleteFolderAction;
use Vaulter\Actions\MoveFolderAction;
use Vaulter\Actions\RenameFolderAction;
use Vaulter\Exceptions\PermissionDeniedException;
use Vaulter\Http\Requests\StoreFolderRequest;
use Vaulter\Http\Resources\DocumentFolderResource;
use Vaulter\Models\DocumentFolder;
use Vaulter\Repositories\DocumentVisibilityService;
use Vaulter\Repositories\FolderReadRepository;
use Vaulter\ValueObjects\WorkspaceRef;

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
