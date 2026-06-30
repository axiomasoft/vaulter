<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Enums\Ability;
use Vaulter\Enums\Effect;
use Vaulter\Enums\GrantLevel;
use Vaulter\Models\Node;
use Vaulter\Models\NodeGrant;
use Vaulter\Models\Storage;
use Vaulter\Repositories\GrantReadRepository;
use Vaulter\Repositories\StorageReadRepository;
use Vaulter\Support\Identity;

/**
 * The Vaulter-OWNED, NON-swappable effective-permission engine (master plan H.2).
 * It runs the canonical algorithm over v_node_grants combined with the pluggable
 * {@see HostRoleDriver}, and is the single authority every Policy / DriveContext /
 * Resource asks. It is deliberately NOT a contract and NOT swappable — only the
 * host-role lane (the driver) is.
 *
 * THE ALGORITHM (H.2 — implemented EXACTLY):
 *   1. Host override (super-admin) — absolute, both directions.
 *   2. Walk ancestors nearest-first, BOUNDED to the workspace/storage root (never
 *      cross storage_id), collecting direct + scope=subtree grants for the
 *      subject-set (from HostRoleDriver::subjectsFor); STOP at the first ancestor
 *      with inherit_grants=false.
 *   3. aclAllow = max(allow levels/bits); deniedBits = OR of deny bits (a deny
 *      denies that level and everything it implies); a NEARER explicit allow
 *      pierces a FARTHER inherited deny; a deny on an inherit_grants=false node is
 *      an un-overridable wall.
 *   4. granted = max(aclAllow, roleAllow) where roleAllow comes from
 *      HostRoleDriver::resolve.
 *   5. FINAL VETO pass: if deniedBits covers the requested ability → DENY
 *      regardless of role grant.
 *
 * Expiry is read-time (filtered in the grant query). The ancestor walk uses the
 * staudenmeir ancestor relation bounded to the storage root — no whereHas /
 * withCount('descendants') (H.4 arch-ban).
 */
final class AccessResolver
{
    public function __construct(
        private readonly HostRoleDriverManager $drivers,
        private readonly GrantReadRepository $grants,
        private readonly StorageReadRepository $storages,
    ) {}

    /**
     * May the actor perform the ability on the node? The public authorization
     * entry point Policies and the DriveContext call.
     */
    public function can(Authorizable $actor, Ability $ability, Node $node): bool
    {
        return $this->effectiveGrants($actor, $node)->allows($ability);
    }

    /**
     * Run the full effective-permission algorithm for (actor, node) and return the
     * folded GrantSet (allow-mask, deny-mask, level + any super override). This is
     * the one place the algorithm lives; can()/abilitiesFor() read its result.
     */
    public function effectiveGrants(Authorizable $actor, Node $node): GrantSet
    {
        $driver = $this->drivers->driver();
        $storage = $this->storageOf($node);
        $scope = $this->scopeFor($storage, $node);

        // ── H.2 step 1: host override (super-admin) — absolute, both directions.
        $override = $driver->override($actor, $scope);

        if ($override === Effect::Allow) {
            return GrantSet::superAllowed();
        }

        if ($override === Effect::Deny) {
            return GrantSet::superDenied();
        }

        $subjects = $driver->subjectsFor($actor);

        // ── H.2 steps 2 + 3: the ACL allow-mask + deny-mask from the bounded walk.
        [$aclAllowBits, $deniedBits, $aclLevel] = $this->resolveAcl(node: $node, subjects: $subjects);

        // ── H.2 step 4: granted = max(aclAllow, roleAllow). The role lane can RAISE
        // the allow-mask but it is folded BEFORE the deny veto, so it cannot
        // resurrect a denied bit.
        [$roleAllowBits, $roleLevel] = $this->resolveRole(
            driver: $driver,
            actor: $actor,
            scope: $scope,
            subjects: $subjects,
        );

        $allowedBits = $aclAllowBits | $roleAllowBits;
        $level = $this->maxLevel($aclLevel, $roleLevel);

        // ── H.2 step 5: the FINAL veto is baked into GrantSet::allows() — an ability
        // whose required bits intersect deniedBits is denied regardless of allow.
        return new GrantSet(
            allowedBits: $allowedBits,
            deniedBits: $deniedBits,
            level: $level,
        );
    }

    /**
     * The UI ability flags for (actor, node) — every Ability mapped to whether the
     * actor may perform it (deny-veto applied). Fills the NodeResource "abilities"
     * object so the client renders only allowed actions.
     *
     * @return array<string, bool>
     */
    public function abilitiesFor(Authorizable $actor, Node $node): array
    {
        $grants = $this->effectiveGrants($actor, $node);

        $flags = [];

        foreach (Ability::cases() as $ability) {
            $flags[$ability->value] = $grants->allows($ability);
        }

        return $flags;
    }

    /**
     * May the actor create nodes in the storage's workspace? Delegates to the
     * host-role driver's workspace gate (the folder/upload create path has no
     * existing node to resolve against). canCreate for folders/uploads (§6).
     */
    public function canCreateInWorkspace(Authorizable $actor, Storage $storage): bool
    {
        return $this->drivers->driver()->canCreateInWorkspace(
            actor: $actor,
            workspaceType: $storage->owner_type,
            workspaceId: $storage->owner_id,
        );
    }

    /**
     * The "shared with me" view (§2.6 / H.2): the nodes an actor is granted any
     * access to, RE-RESOLVED through the full algorithm so a nearer deny that
     * revokes a row excludes it. The fast subject-index pass yields candidate node
     * ids; each candidate is then re-checked with can(View), because a deny nearer
     * the node may have revoked the visibility the raw grant implied.
     *
     * @param  iterable<Node> $candidates already-loaded candidate nodes (e.g. the
     *                                    nodes whose ids nodeIdsSharedWith returned)
     * @return list<Node>
     */
    public function filterSharedWith(Authorizable $actor, iterable $candidates): array
    {
        $visible = [];

        foreach ($candidates as $node) {
            if ($this->can($actor, Ability::View, $node)) {
                $visible[] = $node;
            }
        }

        return $visible;
    }

    /**
     * The candidate node ids an actor has a live allow-grant on — the fast
     * subject-index pass behind filterSharedWith (§2.6). The caller loads the nodes
     * and re-resolves them; this never decides visibility on its own.
     *
     * @return list<int|string>
     */
    public function sharedNodeIds(Authorizable $actor): array
    {
        $subjects = $this->drivers->driver()->subjectsFor($actor);

        return $this->grants->nodeIdsSharedWith(subjects: $subjects, now: Carbon::now());
    }

    /**
     * H.2 steps 2 + 3 — the ACL allow-mask, deny-mask and effective level from the
     * bounded ancestor walk.
     *
     * Walk order is nearest-first (the node itself, then its parents up to the
     * storage root). For each node visited we collect the live grants addressed to
     * the subject-set; only grants that APPLY at this node count:
     *   - a grant ON the node (any scope) applies to the node directly;
     *   - a grant on an ANCESTOR applies only when its scope is subtree.
     * The walk STOPS once it has consumed an ancestor whose inherit_grants=false:
     * that node's own grants still count, but nothing above it does (cut-off).
     *
     * deny semantics (the FINAL veto is absolute per depth): a deny is pierced ONLY
     * by an allow that sits STRICTLY NEARER (a smaller walk depth) — never by an
     * allow on the SAME node. So allow + deny on the same node ⇒ the deny wins
     * (same-node deny is an absolute veto, §2.6 / H.2 step 5). This is why we cannot
     * fold in a single pass over an UNORDERED grant set: a same-depth allow seen
     * before a same-depth deny must NOT swallow it. We instead bucket allow/deny
     * bits PER DEPTH, then fold from nearest to farthest, masking a deny only with
     * bits allowed at a strictly smaller depth. A deny on the cut-off (inherit_grants
     * =false) node is doubly safe: it is the farthest depth in the bounded walk, so
     * no farther allow exists to pierce it anyway.
     *
     * @param  list<Subject>                             $subjects
     * @return array{0: int, 1: int, 2: GrantLevel|null}
     */
    private function resolveAcl(Node $node, array $subjects): array
    {
        if ($subjects === []) {
            return [0, 0, null];
        }

        // The bounded ancestor chain, nearest-first: node, parent, …, root. The
        // staudenmeir ancestor relation is storage-scoped already (the tree lives
        // in one storage_id), so the walk never crosses workspaces.
        $chain = $this->ancestorChain($node);

        $nodeIds = array_map(static fn (Node $n): int|string => $n->id, $chain);

        $now = Carbon::now();
        $grants = $this->grants->forSubjectsOnNodes(nodeIds: $nodeIds, subjects: $subjects, now: $now);

        /** @var array<string, list<NodeGrant>> $byNode */
        $byNode = [];

        foreach ($grants as $grant) {
            $byNode[Identity::stringify($grant->node_id)][] = $grant;
        }

        // Phase 1 — bucket the allow/deny bits PER DEPTH (nearest = 0). We never let
        // a same-node allow and deny cancel here; they are kept apart so phase 2 can
        // apply "a deny is pierced only by a strictly-nearer allow".
        /** @var array<int, int> $allowByDepth */
        $allowByDepth = [];
        /** @var array<int, int> $denyByDepth */
        $denyByDepth = [];
        $level = null;

        foreach ($chain as $depth => $current) {
            $isSelf = $depth === 0;
            $rows = $byNode[Identity::stringify($current->id)] ?? [];

            foreach ($rows as $grant) {
                // An ancestor's grant only inherits down when scope=subtree; the
                // node's own grants always apply.
                if (!$isSelf && !$grant->scope->inheritsDownward()) {
                    continue;
                }

                $bits = $this->bitsFor($grant);

                if ($grant->effect === Effect::Allow) {
                    $allowByDepth[$depth] = ($allowByDepth[$depth] ?? 0) | $bits;
                    $level = $this->maxLevel($level, $grant->level);

                    continue;
                }

                $denyByDepth[$depth] = ($denyByDepth[$depth] ?? 0) | $bits;
            }

            // Cut-off: once we have consumed a node whose inheritance is severed,
            // nothing above it may contribute. The node's OWN grants (bucketed above)
            // already counted; a deny on this wall is permanent because the walk
            // stops here (no farther/nearer allow can ever pierce it).
            if (!$current->inherit_grants) {
                break;
            }
        }

        // Phase 2 — fold. allowedBits is the union of every depth's allow. A deny at
        // depth d is finally denied EXCEPT for the bits allowed at a STRICTLY nearer
        // depth (< d); a same-depth allow does NOT pierce it, so a same-node deny
        // always vetoes a same-node allow.
        $allowedBits = 0;

        foreach ($allowByDepth as $bits) {
            $allowedBits |= $bits;
        }

        $deniedBits = 0;

        foreach ($denyByDepth as $depth => $bits) {
            $nearerAllow = 0;

            foreach ($allowByDepth as $allowDepth => $allowBits) {
                if ($allowDepth < $depth) {
                    $nearerAllow |= $allowBits;
                }
            }

            $deniedBits |= ($bits & ~$nearerAllow);
        }

        return [$allowedBits, $deniedBits, $level];
    }

    /**
     * H.2 step 4 input — the host-role allow-mask + level. A driver Decision RAISES
     * access (roleAllow); a driver-returned deny is folded the same way the ACL
     * deny path is, but per H.2 the role lane primarily contributes allows (the
     * final veto, computed from ACL deny, is what a role can never override).
     *
     * @param  list<Subject>                     $subjects
     * @return array{0: int, 1: GrantLevel|null}
     */
    private function resolveRole(
        HostRoleDriver $driver,
        Authorizable $actor,
        AclScope $scope,
        array $subjects,
    ): array {
        $bits = 0;
        $level = null;

        foreach ($subjects as $subject) {
            $decision = $driver->resolve($subject, $actor, $scope);

            if (!$decision instanceof Decision || !$decision->isAllow()) {
                continue;
            }

            $bits |= $decision->bits;
            $level = $this->maxLevel($level, $decision->level);
        }

        return [$bits, $level];
    }

    /**
     * The CRUDS bits a grant row confers: the explicit permissions bitmask when set,
     * else the level's bitmask. A row may carry both a level and extra bits — they
     * are OR-ed so a level grant plus an extra SHARE bit both count.
     */
    private function bitsFor(NodeGrant $grant): int
    {
        return $grant->level->bits() | $grant->permissions;
    }

    /**
     * The bounded ancestor chain nearest-first: the node, then each ancestor up to
     * the storage root (the staudenmeir ancestor relation, storage-scoped). Falls
     * back to a manual parent walk if the relation yields nothing (defensive — a
     * detached node still resolves against itself).
     *
     * @return list<Node>
     */
    private function ancestorChain(Node $node): array
    {
        $chain = [$node];

        /** @var Collection<int, Node> $ancestors */
        $ancestors = $node->ancestors()->get();

        // ancestors() returns nearest-first already; bound it to the same storage so
        // a misconfigured tree can never escape the workspace (defence-in-depth on
        // top of the storage-scoped relation).
        foreach ($ancestors as $ancestor) {
            if ($ancestor->storage_id !== $node->storage_id) {
                break;
            }

            $chain[] = $ancestor;
        }

        return $chain;
    }

    private function maxLevel(?GrantLevel $a, ?GrantLevel $b): ?GrantLevel
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->max($b);
    }

    private function scopeFor(Storage $storage, Node $node): AclScope
    {
        $owner = $node->owner_id === null
            ? null
            : new Subject(type: $storage->owner_type, id: $node->owner_id);

        return new AclScope(
            panel: $storage->panel,
            workspaceType: $storage->owner_type,
            workspaceId: $storage->owner_id,
            nodeOwner: $owner,
        );
    }

    private function storageOf(Node $node): Storage
    {
        $storage = $this->storages->find($node->storage_id);

        if (!$storage instanceof Storage) {
            // A node always belongs to a storage (FK cascade); a missing storage is
            // a corrupt state — deny by surfacing an empty workspace scope is unsafe,
            // so we fail loud rather than silently open.
            throw new \RuntimeException(sprintf(
                'Vaulter could not resolve the storage [%s] for node [%s].',
                Identity::stringify($node->storage_id),
                Identity::stringify($node->id),
            ));
        }

        return $storage;
    }
}
