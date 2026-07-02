<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Drive\Http\Resources\StorageResource;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
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
     *   - No root node (root_node_id null / the row missing): a storage is ALWAYS
     *     provisioned with its root folder in one transaction (StorageManager::provision),
     *     so a missing root is a corrupt/transient state with no node to authorize against.
     *     It 404s for EVERYONE — never leaking the meta (name, owner, quota) to a grantless
     *     actor — matching the package's deny-on-missing-root convention (ActivityController
     *     "a miss is a corrupt state and 404s" / DocumentNodeController::driveRoot abort(404)).
     *   - Null actor (unauthenticated): denied on a rooted storage — no actor ⇒ no grant
     *     ⇒ 403 (exactly NodeController::guard); on a rootless storage the 404 above fires
     *     first, so the meta never reaches an actor-less caller either way.
     *   - Open drivers (gate/spatie/azgard/null): $access->can delegates to the active
     *     host-role driver, so an open driver decides exactly as it does for the node
     *     listings — the database driver is never hard-coded here.
     */
    public function show(Request $request, Storage $storage): StorageResource
    {
        $root = $this->root($storage);

        if (!$root instanceof Node) {
            // No root folder to gate View on. Provisioning is transactional (the root is
            // born with the storage), so this is a corrupt/transient row, not a viewable
            // "unrooted shell": 404 for everyone rather than serving the meta ungated.
            abort(404);
        }

        $this->guard($request, Ability::View, $root);

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
