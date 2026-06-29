<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Drive;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Vaulter\Drive\DriveContext;
use Vaulter\Enums\NodeKind;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Drive\CopyNodeRequest;
use Vaulter\Http\Requests\Drive\CreateFolderRequest;
use Vaulter\Http\Requests\Drive\MoveNodeRequest;
use Vaulter\Http\Requests\Drive\RenameNodeRequest;
use Vaulter\Http\Requests\Drive\UploadFileRequest;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Node;
use Vaulter\Models\Storage;
use Vaulter\Repositories\NodeReadRepository;
use Vaulter\Repositories\StorageReadRepository;
use Vaulter\VaulterManager;

/**
 * The Drive REST surface over the M1c domain (§5): a thin HTTP layer that resolves
 * a DriveContext from the route-bound storage (Vaulter::forStorage) and delegates
 * every write to a Drive Action through that handle, every read to
 * NodeReadRepository. No model queries live here — storage/node arrive route-model
 * resolved (swappable models bound in the provider) and the rest is repository
 * work.
 *
 * Authorization is NOT wired in this slice (M2): each method carries an explicit
 * `// M2: authorize …` seam mirroring the Action's own authorize() comment, and
 * the endpoints run open behind the configured route middleware.
 */
final class NodeController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly NodeReadRepository $nodes,
        private readonly StorageReadRepository $storages,
    ) {}

    /**
     * List the direct children of a folder (or the storage root when no parent is
     * given), cursor-paginated for large folders.
     */
    public function index(Request $request, Storage $storage): AnonymousResourceCollection
    {
        // M2: authorize 'view' on the parent folder / storage root.
        $parent = $request->filled('parent_id')
            ? $this->findNodeInStorage($this->scalarId($request, 'parent_id'), $storage)
            : $this->driveFor($storage)->root();

        $children = $this->nodes
            ->children(storage: $storage, parent: $parent)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeResource::collection($children);
    }

    /**
     * A single node plus its breadcrumb; a folder also carries its direct children.
     */
    public function show(Request $request, Node $node): NodeResource
    {
        // M2: authorize 'view' on $node.
        $storage = $this->storageOf($node);

        $resource = NodeResource::make($node)
            ->withBreadcrumb($this->nodes->breadcrumb($node));

        if ($node->kind === NodeKind::Folder) {
            $resource->withChildren($this->nodes->children(storage: $storage, parent: $node)->get());
        }

        return $resource;
    }

    public function storeFolder(CreateFolderRequest $request, Storage $storage): JsonResponse
    {
        // M2: authorize 'create' on the destination parent / storage root.
        $drive = $this->driveFor($storage);
        $parent = $this->resolveParentById($request->parentId(), $storage);

        $node = $drive->createFolder(
            name: $request->name(),
            parent: $parent,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    /**
     * Commit an upload (§2.4). A new-version upload targets an existing file node
     * (node_id); otherwise a brand-new file is created under the parent.
     */
    public function upload(UploadFileRequest $request, Storage $storage): JsonResponse
    {
        // M2: authorize 'upload' on the target node / destination parent.
        $drive = $this->driveFor($storage);
        $file = $request->uploadedFile();

        $targetNodeId = $request->targetNodeId();

        if ($targetNodeId !== null) {
            $target = $this->findNodeInStorage($targetNodeId, $storage);

            $node = $drive->uploadVersion(
                target: $target,
                source: $file,
                actorId: $this->actorId($request),
            );

            return NodeResource::make($node)->response()->setStatusCode(201);
        }

        $parent = $this->resolveParentById($request->parentId(), $storage);

        $node = $drive->upload(
            source: $file,
            into: $parent,
            name: $request->name(),
            actorId: $this->actorId($request),
        );

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    public function rename(RenameNodeRequest $request, Node $node): NodeResource
    {
        // M2: authorize 'rename' on $node.
        $renamed = $this->driveFor($this->storageOf($node))->rename(
            node: $node,
            name: $request->name(),
            actorId: $this->actorId($request),
        );

        return NodeResource::make($renamed);
    }

    public function move(MoveNodeRequest $request, Node $node): NodeResource
    {
        // M2: authorize 'move' on $node and 'create' on the destination.
        $storage = $this->storageOf($node);
        $into = $this->resolveParentById($request->parentId(), $storage);

        $moved = $this->driveFor($storage)->move(
            node: $node,
            into: $into,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($moved);
    }

    public function copy(CopyNodeRequest $request, Node $node): JsonResponse
    {
        // M2: authorize 'view' on $node and 'create' on the destination.
        $storage = $this->storageOf($node);
        $into = $this->resolveParentById($request->parentId(), $storage);

        $copy = $this->driveFor($storage)->copy(
            node: $node,
            into: $into,
            name: $request->name(),
            actorId: $this->actorId($request),
        );

        return NodeResource::make($copy)->response()->setStatusCode(201);
    }

    public function trash(Request $request, Node $node): NodeResource
    {
        // M2: authorize 'trash' on $node.
        $trashed = $this->driveFor($this->storageOf($node))->trash(
            node: $node,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($trashed);
    }

    public function restore(Request $request, Node $node): NodeResource
    {
        // M2: authorize 'restore' on $node.
        $restored = $this->driveFor($this->storageOf($node))->restore(
            node: $node,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($restored);
    }

    public function forceDelete(Request $request, Node $node): Response
    {
        // M2: authorize 'delete' (permanent) on $node.
        $this->driveFor($this->storageOf($node))->forceDelete(
            node: $node,
            actorId: $this->actorId($request),
        );

        return response()->noContent();
    }

    /**
     * Name search within the storage tree (live nodes only), cursor-paginated.
     */
    public function search(Request $request, Storage $storage): AnonymousResourceCollection
    {
        // M2: authorize 'view' on the storage; results filtered per node in M2.
        $term = $request->string('q')->toString();

        $results = $this->nodes
            ->search(storage: $storage, term: $term)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeResource::collection($results);
    }

    /**
     * The trash view: trashed (not yet force-deleted) nodes for the storage.
     */
    public function trashed(Request $request, Storage $storage): AnonymousResourceCollection
    {
        // M2: authorize 'view' on the storage trash.
        $results = $this->nodes
            ->trashed(storage: $storage)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeResource::collection($results);
    }

    private function driveFor(Storage $storage): DriveContext
    {
        return $this->vaulter->forStorage(storage: $storage);
    }

    /**
     * The storage a route-bound node belongs to, resolved through the read
     * repository (the swappable storage model) rather than a controller query.
     */
    private function storageOf(Node $node): Storage
    {
        $storage = $this->storages->find($node->storage_id);

        if (!$storage instanceof Storage) {
            abort(404);
        }

        return $storage;
    }

    /**
     * Resolve the destination parent from an already-extracted id (null = root).
     */
    private function resolveParentById(int|string|null $parentId, Storage $storage): ?Node
    {
        if ($parentId === null) {
            return null;
        }

        return $this->findNodeInStorage($parentId, $storage);
    }

    /**
     * Resolve a node by id, asserting it belongs to the given storage tree so a
     * node id from another storage can never be addressed through this storage.
     */
    private function findNodeInStorage(int|string $nodeId, Storage $storage): Node
    {
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node || $node->storage_id !== $storage->getKey()) {
            abort(404);
        }

        return $node;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }
}
