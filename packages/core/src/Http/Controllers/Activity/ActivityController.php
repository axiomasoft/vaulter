<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Activity;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Activity\ActivityResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Activity\ActivityReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The read surface over the append-only activity feed (§2.9 / M2c-activity): the
 * activity for a storage tree or a single node, cursor-paginated newest-first.
 *
 * "Recipients resolved at READ" is exactly this controller: the feed is queried by
 * storage/node scope (no notification rows, no per-user fan-out). Authorization is
 * Ability::View on the scope through the AccessResolver — a node feed needs View on
 * the node; a storage feed needs View on its root node. A denial 403s via
 * PermissionDeniedException; an unauthenticated request is denied (no actor ⇒ no
 * grant). Thin — every read is repository work, the Resource shapes the response.
 */
final class ActivityController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly ActivityReadRepository $activity,
        private readonly NodeReadRepository $nodes,
    ) {}

    /**
     * The feed for a whole storage tree. Authorized by View on the storage's root
     * node (seeing the storage = being able to view its root).
     */
    public function forStorage(Request $request, Storage $storage): AnonymousResourceCollection
    {
        $root = $this->rootNodeOf($storage);

        $this->guardView($request, $root);

        $feed = $this->activity
            ->forStorage($storage, $this->verb($request))
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return ActivityResource::collection($feed);
    }

    /**
     * The feed for a single node (events targeting it), newest-first. Authorized by
     * View on the node.
     */
    public function forNode(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardView($request, $node);

        $feed = $this->activity
            ->forNode($node, $this->verb($request))
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return ActivityResource::collection($feed);
    }

    /**
     * The storage's root folder node, resolved through the read repository (the
     * swappable node model). A storage always has a root (provisioned with it); a
     * miss is a corrupt state and 404s.
     */
    private function rootNodeOf(Storage $storage): Node
    {
        $root = $storage->root_node_id === null ? null : $this->nodes->find($storage->root_node_id);

        if (!$root instanceof Node) {
            abort(404);
        }

        return $root;
    }

    /**
     * The optional verb filter from the query string, or null. An unknown verb is
     * ignored (the feed simply isn't narrowed) rather than 422 — the feed is a
     * forgiving read surface.
     */
    private function verb(Request $request): ?ActivityVerb
    {
        $value = $request->string('verb')->toString();

        return $value === '' ? null : ActivityVerb::tryFrom($value);
    }

    /**
     * Authorize View on the node through the AccessResolver (H.2), throwing 403 on a
     * denial. An unauthenticated request is denied (no actor ⇒ no grant).
     */
    private function guardView(Request $request, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, Ability::View, $node)) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }
}
