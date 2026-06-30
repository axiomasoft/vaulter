<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\StorageResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * Read surface for a storage container (§2.1): its meta, the root node the client
 * opens into, and the used/quota accounting. Thin — the storage arrives
 * route-model resolved (swappable model bound in the provider); the Resource
 * shapes the response.
 *
 * Authorization (M2a / H.2): a storage's "view" maps to View on its ROOT node, so
 * show() guards Ability::View on the storage root through the AccessResolver before
 * returning the meta (mirroring NodeController's per-ACTION guarding); a denial 403s
 * via PermissionDeniedException. The root is resolved READ-ONLY (the BelongsTo on
 * root_node_id, never the provisioning StorageManager — a read endpoint must never
 * write), exactly as StorageResource resolves it for its ability flags.
 */
final class StorageController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly NodeReadRepository $nodes,
    ) {}

    /**
     * Guard View on the storage root before exposing the meta. Edge cases (matched to
     * NodeController's semantics):
     *
     *   - No root node yet (root_node_id null / the row missing): there is no viewable
     *     node to gate on, so an AUTHENTICATED actor opens the storage (a freshly
     *     provisioned-but-unrooted shell its owner just created; StorageResource returns
     *     all-false abilities). An UNAUTHENTICATED caller is denied — storage meta (name,
     *     owner, quota) must never reach a guest, matching the package's deny-on-missing-
     *     root convention (ActivityController/DocumentNodeController).
     *   - Null actor (unauthenticated): denied in BOTH branches — when a root exists,
     *     no actor ⇒ no grant ⇒ 403 (exactly NodeController::guard); when no root exists,
     *     the no-root branch denies the actor-less request before exposing the meta.
     *   - Open drivers (gate/spatie/azgard/null): $access->can delegates to the active
     *     host-role driver, so an open driver decides exactly as it does for the node
     *     listings — the database driver is never hard-coded here.
     */
    public function show(Request $request, Storage $storage): StorageResource
    {
        $root = $this->root($storage);

        if ($root instanceof Node) {
            $this->guard($request, Ability::View, $root);
        } elseif ($this->authorizable($request) === null) {
            // No root node to gate on, but the meta must never reach an UNAUTHENTICATED
            // caller (the route runs under `api` with no auth guard). An authenticated
            // actor still opens a freshly provisioned-but-unrooted storage (its owner's
            // shell); a guest is denied — matching the package's deny-on-missing-root
            // convention (ActivityController/DocumentNodeController) for the no-actor case.
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        return StorageResource::make($storage);
    }

    /**
     * The storage root node, resolved READ-ONLY through the read repository (never the
     * provisioning StorageManager — a read endpoint must not write). Null when the
     * storage has no root_node_id yet, or the row is missing — matching how
     * StorageResource resolves the root for its ability flags.
     */
    private function root(Storage $storage): ?Node
    {
        if ($storage->root_node_id === null) {
            return null;
        }

        return $this->nodes->find($storage->root_node_id);
    }

    /**
     * Authorize an ability on a node through the AccessResolver (H.2), throwing a
     * 403 PermissionDeniedException when denied. An unauthenticated request is also
     * denied (no actor ⇒ no grant). Mirrors NodeController::guard.
     */
    private function guard(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
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
