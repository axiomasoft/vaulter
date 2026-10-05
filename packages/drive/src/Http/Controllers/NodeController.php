<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ExactListing;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;
use Vaulter\Drive\Http\Requests\Folders\CreateFolderRequest;
use Vaulter\Drive\Http\Requests\Nodes\CopyNodeRequest;
use Vaulter\Drive\Http\Requests\Nodes\MoveNodeRequest;
use Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;
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
 * Listings scan ordered candidates through the same ACL resolver as actions.
 * The signed cursor advances after the last inspected candidate, including denied
 * nodes, so a bounded empty page still permits reaching later visible nodes.
 */
final class NodeController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly NodeReadRepository $nodes,
        private readonly DriveReadRepository $storages,
        private readonly AccessResolver $access,
        private readonly ExactListing $listing,
    ) {}

    /** List visible direct children using a signed cursor. */
    public function index(Request $request, Drive $storage): AnonymousResourceCollection
    {
        $parent = $request->filled('parent_id')
            ? $this->findNodeInStorage($this->scalarId($request, 'parent_id'), $storage)
            : $this->driveFor($storage)->root();

        $this->guard($request, Ability::View, $parent);

        return $this->listingResponse($request,
            $this->nodes->children(storage: $storage, parent: $parent),
            [['column' => 'position', 'direction' => 'asc'], ['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            "children:{$storage->id}:{$parent->id}",
        );
    }

    /** List visible nodes with a live direct allow-grant across storages. */
    public function shared(Request $request): AnonymousResourceCollection
    {
        $actor = $this->authorizable($request);
        $model = Config::nodeModel();
        $query = $model::query()->whereNull('trashed_at')->whereKey($actor === null ? [] : $this->access->sharedNodeIds($actor));

        return $this->listingResponse($request, $query,
            [['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            'shared:*:',
        );
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
            $children = $this->nodes->children(storage: $storage, parent: $node)->get();
            $actor = $this->authorizable($request);
            $sets = $actor === null ? [] : $this->access->evaluateMany($actor, $children);
            $resource->withChildren($children->filter(fn (Node $child): bool => isset($sets[(string) $child->id]) && $sets[(string) $child->id]->allows(Ability::View))->values())
                ->withChildrenGrantSets($sets);
        }

        return $resource;
    }

    public function storeFolder(CreateFolderRequest $request, Drive $storage): JsonResponse
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
    public function upload(UploadFileRequest $request, Drive $storage): JsonResponse
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

    /** Search live nodes with exact visibility and a signed cursor. */
    public function search(Request $request, Drive $storage): AnonymousResourceCollection
    {
        $term = $request->string('q')->toString();

        return $this->listingResponse($request, $this->nodes->search(storage: $storage, term: $term),
            [['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            "search:{$storage->id}:".sha1($term),
        );
    }

    /** List trashed nodes with exact visibility and a signed cursor. */
    public function trashed(Request $request, Drive $storage): AnonymousResourceCollection
    {
        return $this->listingResponse($request, $this->nodes->trashed(storage: $storage),
            [['column' => 'trashed_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
            "trashed:{$storage->id}:",
        );
    }

    private function driveFor(Drive $storage): DriveHandle
    {
        return $this->vaulter->forStorage(storage: $storage);
    }

    /**
     * The storage a route-bound node belongs to, resolved through the read
     * repository (the swappable storage model) rather than a controller query.
     */
    private function storageOf(Node $node): Drive
    {
        $storage = $this->storages->find($node->drive_id);

        if (!$storage instanceof Drive) {
            abort(404);
        }

        return $storage;
    }

    /**
     * Resolve the destination parent from an already-extracted id (null = root).
     */
    private function resolveParentById(int|string|null $parentId, Drive $storage): ?Node
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
    private function findNodeInStorage(int|string $nodeId, Drive $storage): Node
    {
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node || $node->drive_id !== $storage->getKey()) {
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
    private function guardCreate(Request $request, Drive $storage, Node $destination): void
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
     * @param Builder<Node>                                      $query
     * @param list<array{column:string, direction:'asc'|'desc'}> $order
     */
    private function listingResponse(Request $request, Builder $query, array $order, string $context): AnonymousResourceCollection
    {
        $page = $this->listing->page(
            actor: $this->authorizable($request), candidates: $query, order: $order,
            perPage: $this->perPage($request), cursor: $request->filled('cursor') ? $request->string('cursor')->toString() : null,
            context: $context,
        );
        $resources = array_map(fn (Node $node): NodeResource => NodeResource::make($node)->withGrantSet($this->listing->grantSetFor($node)), $page->items);

        return NodeResource::collection($resources)->additional(['meta' => [
            'path' => $request->url(), 'per_page' => $this->perPage($request),
            'next_cursor' => $page->nextCursor, 'prev_cursor' => null, 'scanned' => $page->scanned,
        ]]);
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
