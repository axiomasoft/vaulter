<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
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
 * Authorization (M2a / H.2): every per-ACTION method guards the relevant Ability on
 * the node (or canCreate on the destination) through the AccessResolver before acting,
 * EXACTLY (deny-veto + inherit-cutoff applied); a denial 403s via
 * PermissionDeniedException.
 *
 * LISTINGS (index/search/trash) follow a CANDIDATE-SET semantic (P1 #3): the query is
 * narrowed to the actor's grant-reachable candidate set in SQL BEFORE pagination
 * (constrainToAccessible), so nodes with no grant-reachability — the common
 * "inaccessible" / "not shared with me" case — are hidden. This narrowing is a
 * deliberate SUPERSET that does NOT apply the exact deny-veto / inherit-cutoff in the
 * listing SQL (a node a nearer deny revokes can still list); that exact listing
 * suppression is DEFERRED to the H.4 materialised effective-grants read-model. The
 * narrowing is in the QUERY, never a post-fetch page filter — a post-filter that
 * empties a cursor page makes nextCursor() null and strands later authorized rows.
 * This matches the Filament table (also candidate-SQL). The cross-storage "shared with
 * me" view re-resolves each candidate EXACTLY (bounded set). For the open drivers
 * (gate/spatie/azgard/null) grant-filtering does not apply and listings stay OPEN.
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
     *
     * Access-aware narrowing (P1 #3): when grant-filtering applies the children query
     * is constrained to the actor's grant-reachable CANDIDATE set BEFORE pagination,
     * so a folder the actor has no grant-reachability to yields zero candidate children
     * and never leaks. The narrowing is a SUPERSET (see the controller docblock): the
     * exact deny-veto suppression in listings is deferred to the H.4 read-model, while
     * per-ACTION authorization (show/mutations) stays exact via the AccessResolver.
     * Pushing the narrowing into the SQL — not post-filtering the fetched page — keeps
     * the cursor honest: a post-filter that empties a page makes nextCursor() null and
     * silently strands the rest of the listing.
     */
    public function index(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $parent = $request->filled('parent_id')
            ? $this->findNodeInStorage($this->scalarId($request, 'parent_id'), $storage)
            : $this->driveFor($storage)->root();

        $this->guard($request, Ability::View, $parent);

        $children = $this->narrowToAccessible($request, $this->nodes->children(storage: $storage, parent: $parent))
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeResource::collection($children);
    }

    /**
     * The "shared with me" view (§2.6 / H.2): the nodes the actor holds a live
     * allow-grant on, re-resolved through the full algorithm so a nearer deny that
     * revokes a row excludes it (the EXACT gate over a bounded candidate set). Cross-
     * storage by nature — the subject index is not storage-bound. An unauthenticated
     * request sees nothing.
     *
     * BOUNDED (P1 #3 / finding G): the set is cross-storage and unbounded, and the
     * exact re-resolution returns a post-filter Collection that cannot cursor-paginate
     * cleanly. So the resolved collection is wrapped in a LengthAwarePaginator at the
     * same per_page as the sibling listings — a simple page envelope (page/total),
     * NOT a cursor envelope it cannot honestly back.
     */
    public function shared(Request $request): AnonymousResourceCollection
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            return NodeResource::collection(new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $this->perPage($request),
            ));
        }

        $shared = $this->nodes->sharedWith($actor);
        $perPage = $this->perPage($request);
        $page = LengthAwarePaginator::resolveCurrentPage();

        $paginated = new LengthAwarePaginator(
            items: $shared->forPage(page: $page, perPage: $perPage)->values(),
            total: $shared->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );

        return NodeResource::collection($paginated);
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
     * Name search within the storage tree (live nodes only), cursor-paginated. The
     * results are narrowed to the actor's grant-reachable candidate set BEFORE
     * pagination (P1 #3) — same candidate-SQL semantic as index(), so a match the
     * actor has no grant-reachability to never leaks and the cursor stays honest.
     */
    public function search(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $term = $request->string('q')->toString();

        $results = $this->narrowToAccessible($request, $this->nodes->search(storage: $storage, term: $term))
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeResource::collection($results);
    }

    /**
     * The trash view: trashed (not yet force-deleted) nodes for the storage, narrowed
     * to the actor's grant-reachable candidate set BEFORE pagination (P1 #3 — same
     * candidate-SQL semantic as index(), cursor stays honest). The candidate set is
     * grant-reachability, NOT a per-row Restore check: per-ACTION authorization
     * (restore/forceDelete) stays exact via the AccessResolver on the mutation.
     */
    public function trashed(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $results = $this->narrowToAccessible($request, $this->nodes->trashed(storage: $storage))
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
     * Narrow a listing query to the actor's grant-reachable candidate set BEFORE it is
     * paginated (P1 #3) — the access-aware listing seam. A no-op when grant-filtering
     * does not apply (open drivers); an empty result for an unauthenticated request (no
     * actor ⇒ no grant ⇒ no candidate). The narrowing lives in the SQL so the cursor
     * never strands later rows, and the per-row exact gate stays on the per-ACTION path
     * (show/mutations through the AccessResolver), not on the listing.
     *
     * @param  Builder<Node> $query
     * @return Builder<Node>
     */
    private function narrowToAccessible(Request $request, Builder $query): Builder
    {
        if (!$this->nodes->grantFilteringApplies()) {
            return $query;
        }

        $actor = $this->authorizable($request);

        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->nodes->constrainToAccessible($query, $actor);
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
