<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Queries\NodeBrowserQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;
use Vaulter\Http\Support\Preconditions;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;

/**
 * How the drive routes answer with nodes. Every node goes out as the caller's own
 * representation, with abilities from one batch evaluation per response, and a single
 * node carries its representation tag in the ETag header as well as in the body.
 *
 * It is also where a conditional mutation learns what to expect: the If-Match header is
 * compared with the tag of the representation the caller would get right now, after the
 * node has been found as this caller and the caller was seen to hold the ability the
 * mutation needs (so an invisible node is a 404 and a missing ability a 403 before any
 * 428 or 412), and the matching sequence is what the operation then checks again,
 * atomically, while it writes.
 */
final readonly class NodeResponses
{
    public function __construct(
        private NodeLocator $locator,
        private NodeSnapshotMapper $mapper,
        private NodeBrowserQuery $browser,
        private HttpResourceResolver $resolver,
        private HttpOperationContextFactory $contexts,
    ) {}

    /**
     * Run a call addressed by a drive id, possibly naming a node inside it (a parent
     * folder, a target file). When something inside is not found and the caller cannot
     * see the drive's root either, the answer is drive_not_found — the same as for an
     * unknown drive — so a child id never reveals that an invisible drive exists. A
     * caller who sees the drive gets node_not_found for the missing node.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function withinDrive(Request $request, string $driveId, Closure $call): mixed
    {
        $drive = null;
        try {
            $drive = $this->contexts->forDrive($request, $this->resolver->drive($driveId));

            return $call($drive);
        } catch (NodeNotFoundException $missing) {
            if ($drive === null) {
                throw StorageNotFoundException::forId($driveId);
            }
            try {
                $drive->root();
            } catch (NodeNotFoundException) {
                throw StorageNotFoundException::forId($driveId);
            }

            throw $missing;
        }
    }

    /**
     * A node a mutation just returned. The change stands even if it took the caller's
     * own access away (a move under a restricted folder); the answer then says so with
     * every ability off.
     */
    public function resource(DriveHandle $drive, NodeSnapshot $node): NodeResource
    {
        return new NodeResource($node, $drive->nodes()->abilitiesMany([$node])[$node->id] ?? NodeAbilities::denied());
    }

    public function respond(DriveHandle $drive, NodeSnapshot $node, int $status = 200): JsonResponse
    {
        $resource = $this->resource($drive, $node);

        return $resource->response()->setStatusCode($status)->setEtag($resource->etag());
    }

    /** @param Page<NodeSnapshot> $page */
    public function page(DriveHandle $drive, Page $page, int $perPage): JsonResponse
    {
        $resources = NodeResource::many($page->items, $drive->nodes()->abilitiesMany($page->items));

        return PageResponse::make(new Page(items: $resources, nextCursor: $page->nextCursor), static fn (NodeResource $resource): NodeResource => $resource, $perPage);
    }

    /**
     * The caller's current representation of a node it can view. A trashed node is
     * looked up only for the routes that act on the trash (restore, purge).
     */
    public function current(Request $request, DriveHandle $drive, string $nodeId, bool $includeTrashed = false): NodeResource
    {
        [$snapshot, $abilities] = $this->located($request, $drive, $nodeId, $includeTrashed);

        return new NodeResource($snapshot, $abilities);
    }

    /**
     * The sequence a conditional mutation expects. The caller must hold $ability on the
     * node (403 otherwise — the operation authorizes again when it writes); If-Match is
     * required (428 without it or with `*`) and must equal the caller's current tag (412
     * otherwise).
     */
    public function expectedSequence(Request $request, DriveHandle $drive, string $nodeId, Ability $ability, bool $includeTrashed = false): int
    {
        [$snapshot, $abilities] = $this->located($request, $drive, $nodeId, $includeTrashed);
        if (!($abilities->flags[$ability->value] ?? false)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }

        return Preconditions::matchNode($request, (new NodeResource($snapshot, $abilities))->etag(), $snapshot);
    }

    /**
     * The node as the caller sees it and the caller's abilities on it; a node the caller
     * cannot view is not found.
     *
     * @return array{NodeSnapshot, NodeAbilities}
     */
    private function located(Request $request, DriveHandle $drive, string $nodeId, bool $includeTrashed): array
    {
        $snapshot = $includeTrashed
            ? $this->mapper->map($this->locator->find($this->context($request, $drive), $nodeId, includeTrashed: true), $drive->actor()->ref())
            : $drive->nodes()->find($nodeId);

        return [$snapshot, $drive->nodes()->abilitiesMany([$snapshot])[$snapshot->id] ?? throw NodeNotFoundException::forId($nodeId)];
    }

    /**
     * The visible ancestors of a node, root first and the node itself last.
     *
     * @return list<array{id: string, name: string}>
     */
    public function breadcrumb(Request $request, DriveHandle $drive, NodeSnapshot $node): array
    {
        $model = $this->locator->find($this->context($request, $drive), $node->id);

        return array_map(
            static fn (NodeSnapshot $crumb): array => ['id' => $crumb->id, 'name' => $crumb->name],
            $this->browser->breadcrumb($drive, $model),
        );
    }

    private function context(Request $request, DriveHandle $drive): OperationContext
    {
        return new OperationContext(drive: $drive->model(), actor: $drive->actor(), correlationId: AssignCorrelationId::of($request) ?? (string) Str::ulid());
    }
}
