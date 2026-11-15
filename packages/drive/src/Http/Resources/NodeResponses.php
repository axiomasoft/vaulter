<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StaleNodeException;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Queries\NodeBrowserQuery;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\Preconditions;

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
#[Internal]
final readonly class NodeResponses
{
    /** The optional precondition on the destination folder of a move (CC3). */
    public const string DESTINATION_IF_MATCH = 'X-Vaulter-Destination-If-Match';

    public function __construct(
        private NodeLocator $locator,
        private NodeSnapshotMapper $mapper,
        private NodeBrowserQuery $browser,
        private HttpOperationContextFactory $contexts,
    ) {}

    /**
     * Run a call addressed by a drive id, possibly naming a node inside it; see
     * {@see HttpOperationContextFactory::withinDrive()}.
     *
     * @template T
     *
     * @param  Closure(DriveHandle): T $call
     * @return T
     */
    public function withinDrive(Request $request, string $driveId, Closure $call): mixed
    {
        return $this->contexts->withinDrive($request, $driveId, $call);
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

        if (!Preconditions::matches($request, (new NodeResource($snapshot, $abilities))->etag())) {
            throw StaleNodeException::forNode($snapshot->id);
        }

        return $snapshot->sequence;
    }

    /**
     * {@see expectedSequence()} for the route of a receipt operation: a retry under its Idempotency-Key repeats
     * the If-Match of the first request, stale once that one committed — and its node may be in the trash or gone
     * from the caller's view since. The operation answers it from the receipt, or — without one — refuses it as stale
     * or missing and stores that refusal. It gets a sequence no live node holds: PHP_INT_MAX, or the one below it for
     * a node that holds PHP_INT_MAX itself. Without a key the refusal stands.
     */
    public function expectedSequenceForReplay(Request $request, DriveHandle $drive, string $nodeId, Ability $ability, bool $includeTrashed = false): int
    {
        try {
            return $this->expectedSequence($request, $drive, $nodeId, $ability, $includeTrashed);
        } catch (StaleNodeException|NodeNotFoundException $refusal) {
            return $this->replaySentinel($request, $drive, $nodeId, $includeTrashed, $refusal);
        }
    }

    /**
     * For a route whose operation does not compare a matching If-Match again (a new-revision upload session: the
     * commit at the end does not compare it): null when the tag matches, after the same checks as
     * {@see expectedSequence()}; the sentinel of {@see expectedSequenceForReplay()} when a keyed retry is stale or its
     * node gone, so the operation answers it from the receipt or refuses it as stale (and stores that refusal).
     */
    public function staleSequenceForReplay(Request $request, DriveHandle $drive, string $nodeId, Ability $ability): ?int
    {
        try {
            $this->expectedSequence($request, $drive, $nodeId, $ability);

            return null;
        } catch (StaleNodeException|NodeNotFoundException $refusal) {
            return $this->replaySentinel($request, $drive, $nodeId, false, $refusal);
        }
    }

    /**
     * The sequence of the destination folder of a move the caller pinned with `X-Vaulter-Destination-If-Match` (CC3),
     * or null when the header is absent. The tag must equal the caller's current representation of the folder (the
     * drive root when `$destinationId` is null), else 412 node_sequence_mismatch; the move checks the sequence again
     * under its tree lock. A keyed retry repeats the destination tag of the first request, stale once that one
     * committed: like {@see expectedSequenceForReplay()} it gets a sequence no live folder holds, and the operation
     * answers it from the receipt or refuses it (and stores that refusal).
     */
    public function expectedDestinationSequence(Request $request, DriveHandle $drive, ?string $destinationId): ?int
    {
        $header = $request->headers->get(self::DESTINATION_IF_MATCH);
        if ($header === null || trim($header) === '') {
            return null;
        }
        $id = $destinationId ?? $drive->root()->id;
        try {
            [$snapshot, $abilities] = $this->located($request, $drive, $id, false);
            preg_match_all('/\s*(\*|(?:W\/)?"[^"]*")\s*(?:,|$)/', $header, $matches);
            $current = (new NodeResource($snapshot, $abilities))->etag();
            foreach ($matches[1] as $tag) {
                if (!str_starts_with($tag, 'W/') && hash_equals($current, $tag)) {
                    return $snapshot->sequence;
                }
            }

            throw StaleNodeException::forNode($snapshot->id);
        } catch (StaleNodeException|NodeNotFoundException $refusal) {
            return $this->replaySentinel($request, $drive, $id, false, $refusal);
        }
    }

    /** The sequence no live node holds, for a keyed retry refused as stale or missing; without a key the refusal stands. */
    private function replaySentinel(Request $request, DriveHandle $drive, string $nodeId, bool $includeTrashed, StaleNodeException|NodeNotFoundException $refusal): int
    {
        if (ReadIdempotencyKey::key($request) === null) {
            throw $refusal;
        }
        $held = $refusal instanceof StaleNodeException ? $this->located($request, $drive, $nodeId, $includeTrashed)[0]->sequence : null;

        return $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
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

    private function context(Request $request, DriveHandle $drive): DriveContext
    {
        return DriveContext::for(drive: $drive->model(), actor: $drive->actor(), correlationId: AssignCorrelationId::of($request) ?? (string) Str::ulid());
    }
}
