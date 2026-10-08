<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Http\Requests\Files\UploadFileRequest;
use Vaulter\Drive\Http\Requests\Folders\CreateFolderRequest;
use Vaulter\Drive\Http\Requests\Nodes\CopyNodeRequest;
use Vaulter\Drive\Http\Requests\Nodes\MoveNodeRequest;
use Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest;
use Vaulter\Drive\Queries\NodeBrowserQuery;
use Vaulter\Drive\Queries\SharedWithActorQuery;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\VaulterManager;

/** The existing REST representation over the authorized PHP operations. */
final class NodeController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly NodeBrowserQuery $browser,
        private readonly SharedWithActorQuery $sharedNodes,
        private readonly NodeSnapshotMapper $mapper,
    ) {}

    public function index(Request $request, Drive $storage): AnonymousResourceCollection
    {
        $page = $this->driveFor($request, $storage)->nodes()->children(
            folder: $request->filled('parent_id') ? $this->scalarId($request, 'parent_id') : null,
            options: $this->listOptions($request),
        );

        return $this->listingResponse($request, $page);
    }

    public function shared(Request $request): AnonymousResourceCollection
    {
        $page = $this->sharedNodes->execute(actor: $this->authorizable($request), options: $this->listOptions($request));
        $user = $this->authorizable($request);

        return $this->listingResponse($request, $this->mapper->page($page, Actor::user($user)->ref()));
    }

    public function show(Request $request, Node $node): NodeResource
    {
        $drive = $this->driveFor($request, $this->browser->driveForNode($node));
        $snapshot = $drive->nodes()->find(Identity::stringify($node->getKey()));
        $resource = NodeResource::make($snapshot)->withBreadcrumb($this->browser->breadcrumb($drive, $node));

        if ($snapshot->kind === NodeKind::Folder) {
            $items = [];
            $cursor = null;
            do {
                $page = $drive->nodes()->children(folder: $snapshot->id, options: new ListOptions(perPage: 200, cursor: $cursor));
                array_push($items, ...$page->items);
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            $resource->withChildren($items)->withChildrenGrantSets($this->browser->grantSets($this->authorizable($request), $items));
        }

        return $resource;
    }

    public function storeFolder(CreateFolderRequest $request, Drive $storage): JsonResponse
    {
        $node = $this->driveFor($request, $storage)->folders()->create(
            name: $request->name(), parent: $request->parentId(),
        );

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    public function upload(UploadFileRequest $request, Drive $storage): JsonResponse
    {
        $drive = $this->driveFor($request, $storage);
        $source = UploadSource::fromUploadedFile($request->uploadedFile());
        $target = $request->targetNodeId();
        if ($target !== null) {
            $drive->files()->addRevision(file: $target, source: $source);
            $node = $drive->nodes()->find($target);
        } else {
            $node = $drive->files()->upload(source: $source, options: new UploadOptions(
                parent: $request->parentId(), name: $request->name(),
            ));
        }

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    public function rename(RenameNodeRequest $request, Node $node): NodeResource
    {
        return NodeResource::make($this->driveFor($request, $this->browser->driveForNode($node))->nodes()->rename(
            node: Identity::stringify($node->getKey()), name: $request->name(),
        ));
    }

    public function move(MoveNodeRequest $request, Node $node): NodeResource
    {
        return NodeResource::make($this->driveFor($request, $this->browser->driveForNode($node))->nodes()->move(
            node: Identity::stringify($node->getKey()), destination: $request->parentId(),
        ));
    }

    public function copy(CopyNodeRequest $request, Node $node): JsonResponse
    {
        $copy = $this->driveFor($request, $this->browser->driveForNode($node))->nodes()->copy(
            node: Identity::stringify($node->getKey()), destination: $request->parentId(), options: new CopyOptions(name: $request->name()),
        );

        return NodeResource::make($copy)->response()->setStatusCode(201);
    }

    public function trash(Request $request, Node $node): NodeResource
    {
        return NodeResource::make($this->driveFor($request, $this->browser->driveForNode($node))->nodes()->trash(Identity::stringify($node->getKey())));
    }

    public function restore(Request $request, Node $node): NodeResource
    {
        return NodeResource::make($this->driveFor($request, $this->browser->driveForNode($node))->trash()->restore(Identity::stringify($node->getKey())));
    }

    public function forceDelete(Request $request, Node $node): Response
    {
        $this->driveFor($request, $this->browser->driveForNode($node))->trash()->purge(Identity::stringify($node->getKey()));

        return response()->noContent();
    }

    public function search(Request $request, Drive $storage): AnonymousResourceCollection
    {
        $page = $this->driveFor($request, $storage)->nodes()->search(
            term: $request->string('q')->toString(), options: new SearchOptions(
                perPage: $this->perPage($request), cursor: $this->cursor($request),
            ),
        );

        return $this->listingResponse($request, $page);
    }

    public function trashed(Request $request, Drive $storage): AnonymousResourceCollection
    {
        return $this->listingResponse($request, $this->driveFor($request, $storage)->trash()->list($this->listOptions($request)));
    }

    private function driveFor(Request $request, Drive $storage): DriveHandle
    {
        return $this->vaulter->drive(ownerOrDrive: $storage)->actingAs($this->authorizable($request));
    }

    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 50), 200));
    }

    private function cursor(Request $request): ?string
    {
        return $request->filled('cursor') ? $request->string('cursor')->toString() : null;
    }

    private function listOptions(Request $request): ListOptions
    {
        return new ListOptions(perPage: $this->perPage($request), cursor: $this->cursor($request));
    }

    /** @param Page<NodeSnapshot> $page */
    private function listingResponse(Request $request, Page $page): AnonymousResourceCollection
    {
        $sets = $this->browser->grantSets($this->authorizable($request), $page->items);
        $resources = array_map(static fn (NodeSnapshot $node): NodeResource => NodeResource::make($node)->withGrantSet($sets[$node->id]), $page->items);

        return NodeResource::collection($resources)->additional(['meta' => [
            'path' => $request->url(), 'per_page' => $this->perPage($request),
            'next_cursor' => $page->nextCursor, 'prev_cursor' => null, 'scanned' => $page->scanned,
        ]]);
    }

    private function authorizable(Request $request): Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : throw MissingActorException::required();
    }
}
