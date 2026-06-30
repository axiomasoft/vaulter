<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\AccessResolver;
use Vaulter\Drive\DriveContext;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Requests\Drive\CopyNodeRequest;
use Vaulter\Http\Requests\Drive\CreateFolderRequest;
use Vaulter\Http\Requests\Drive\MoveNodeRequest;
use Vaulter\Http\Requests\Drive\RenameNodeRequest;
use Vaulter\Http\Requests\Drive\UploadFileRequest;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\VaulterManager;

/**
 * The Drive REST surface over the M1c domain (§5): a thin HTTP layer that resolves
 * a DriveContext from the route-bound storage (Vaulter::forStorage) and delegates
 * every write to a Drive Action through that handle, every read to
 * NodeReadRepository. No model queries live here — storage/node arrive route-model
 * resolved (swappable models bound in the provider) and the rest is repository
 * work.
 *
 * Authorization (M2a / H.2): every method guards the relevant Ability on the node
 * (or canCreate on the destination) through the AccessResolver before acting; a
 * denial 403s via PermissionDeniedException. Listings filter per node so a nearer
 * deny revokes a row.
 */
final class NodeController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly NodeReadRepository $nodes,
        private readonly StorageReadRepository $storages,
        private readonly AccessResolver $access,
    ) {}

    /**
     * List the direct children of a folder (or the storage root when no parent is
     * given), cursor-paginated for large folders.
     */
    public function index(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $parent = $request->filled('parent_id')
            ? $this->findNodeInStorage($this->scalarId($request, 'parent_id'), $storage)
            : $this->driveFor($storage)->root();

        $this->guard($request, Ability::View, $parent);

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
        $this->guard($request, Ability::View, $node);

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
        $drive = $this->driveFor($storage);
        $parent = $this->resolveParentById($request->parentId(), $storage);

        $this->guardCreate($request, $storage, $parent ?? $drive->root());

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
        $drive = $this->driveFor($storage);
        $file = $request->uploadedFile();

        $targetNodeId = $request->targetNodeId();

        if ($targetNodeId !== null) {
            $target = $this->findNodeInStorage($targetNodeId, $storage);

            // A new version is an UPDATE of the existing file node.
            $this->guard($request, Ability::Edit, $target);

            $node = $drive->uploadVersion(
                target: $target,
                source: $file,
                actorId: $this->actorId($request),
            );

            return NodeResource::make($node)->response()->setStatusCode(201);
        }

        $parent = $this->resolveParentById($request->parentId(), $storage);

        $this->guardCreate($request, $storage, $parent ?? $drive->root());

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
        $this->guard($request, Ability::Edit, $node);

        $renamed = $this->driveFor($this->storageOf($node))->rename(
            node: $node,
            name: $request->name(),
            actorId: $this->actorId($request),
        );

        return NodeResource::make($renamed);
    }

    public function move(MoveNodeRequest $request, Node $node): NodeResource
    {
        $storage = $this->storageOf($node);
        $into = $this->resolveParentById($request->parentId(), $storage);

        // Move = the node leaves its old parent (move on the node) AND lands under
        // the destination (create there).
        $this->guard($request, Ability::Move, $node);
        $this->guardCreate($request, $storage, $into ?? $this->driveFor($storage)->root());

        $moved = $this->driveFor($storage)->move(
            node: $node,
            into: $into,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($moved);
    }

    public function copy(CopyNodeRequest $request, Node $node): JsonResponse
    {
        $storage = $this->storageOf($node);
        $into = $this->resolveParentById($request->parentId(), $storage);

        // Copy = read the source (view) AND create at the destination.
        $this->guard($request, Ability::View, $node);
        $this->guardCreate($request, $storage, $into ?? $this->driveFor($storage)->root());

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
        $this->guard($request, Ability::Trash, $node);

        $trashed = $this->driveFor($this->storageOf($node))->trash(
            node: $node,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($trashed);
    }

    public function restore(Request $request, Node $node): NodeResource
    {
        $this->guard($request, Ability::Restore, $node);

        $restored = $this->driveFor($this->storageOf($node))->restore(
            node: $node,
            actorId: $this->actorId($request),
        );

        return NodeResource::make($restored);
    }

    public function forceDelete(Request $request, Node $node): Response
    {
        $this->guard($request, Ability::Delete, $node);

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
        $term = $request->string('q')->toString();

        $results = $this->nodes
            ->search(storage: $storage, term: $term)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        // Per-node visibility: a match the actor cannot View is dropped from the
        // page (H.2 — a nearer deny revokes a row). Pagination keeps its cursor.
        $this->filterPage($request, $results, Ability::View);

        return NodeResource::collection($results);
    }

    /**
     * The trash view: trashed (not yet force-deleted) nodes for the storage.
     */
    public function trashed(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $results = $this->nodes
            ->trashed(storage: $storage)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        // Only nodes the actor may Restore (DELETE bit) belong in their trash view.
        $this->filterPage($request, $results, Ability::Restore);

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

    /**
     * Authorize an ability on a node through the AccessResolver (H.2), throwing a
     * 403 PermissionDeniedException when denied. An unauthenticated request is also
     * denied (no actor ⇒ no grant). This is the controller seam M1c left open.
     */
    private function guard(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    /**
     * Authorize a CREATE under a destination (§6): the actor must pass the host-role
     * driver's workspace-create gate AND hold the CREATE bit on the destination
     * node (the AccessResolver enforces the latter). Throws 403 on either failure.
     */
    private function guardCreate(Request $request, Storage $storage, Node $destination): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null
            || !$this->access->canCreateInWorkspace($actor, $storage)
            || !$this->access->can($actor, Ability::Create, $destination)
        ) {
            throw PermissionDeniedException::forAbility(Ability::Create->value);
        }
    }

    /**
     * Drop from the current page every node the actor may not perform $ability on
     * (per-node visibility for the search/trash listings, H.2). The cursor is
     * preserved; only the page's collection is narrowed.
     *
     * @param CursorPaginator<int, Node> $page
     */
    private function filterPage(Request $request, CursorPaginator $page, Ability $ability): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            $page->setCollection($page->getCollection()->take(0)->values());

            return;
        }

        $page->setCollection(
            $page->getCollection()
                ->filter(fn (Node $node): bool => $this->access->can($actor, $ability, $node))
                ->values(),
        );
    }

    /**
     * The acting authorizable for the request, or null when unauthenticated.
     */
    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
