<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\AccessAce;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\AclMask;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantReadRepository;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * The Vaulter-OWNED, NON-swappable effective-permission engine (master plan H.2).
 * It runs the canonical algorithm over v_node_grants combined with the pluggable
 * {@see PermissionDriver}, and is the single authority every Policy / DriveContext /
 * Resource asks. It is deliberately NOT a contract and NOT swappable — only the
 * host-role lane (the driver) is.
 *
 * THE ALGORITHM (H.2 — implemented EXACTLY):
 *   1. Host override (super-admin) — absolute, both directions.
 *   2. Walk ancestors nearest-first, BOUNDED to the workspace/storage root (never
 *      cross drive_id), collecting direct + scope=subtree grants for the
 *      subject-set (from PermissionDriver::subjectsFor); STOP at the first ancestor
 *      with inherit_grants=false.
 *   3. aclAllow = max(allow levels/bits); deniedBits = OR of deny bits (a deny
 *      denies that level and everything it implies); a NEARER explicit allow
 *      pierces a FARTHER inherited deny (ADR-0007). An inherit_grants=false node is
 *      a wall for grants ABOVE it (they are cut off), NOT an absolute barrier for a
 *      deny sitting ON it: a nearer explicit allow BELOW the wall still pierces that
 *      deny, exactly as it would any other inherited deny — ADR-0007 gives the wall
 *      no exception. (An absolute, un-pierceable wall-deny would be a separate
 *      product decision, not the current behaviour.)
 *   4. granted = max(aclAllow, roleAllow) where roleAllow comes from
 *      PermissionDriver::resolve.
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
        private readonly VaulterConfig $vaulterConfig,
        private readonly PermissionDriverManager $drivers,
        private readonly GrantReadRepository $grants,
        private readonly DriveReadRepository $storages,
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
        if (app(TenancyBoundary::class)->enforced()) {
            app(TenancyBoundary::class)->assertNode($node);
            $node = $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
        }
        $storage = $this->storageOf($node);
        $driver = $this->drivers->forProfile($storage->profile);
        $scope = $this->scopeFor($storage, $node);

        // ── H.2 step 1: host override (super-admin) — absolute, both directions.
        $override = $driver->override($actor, $scope);

        if ($override === Effect::Allow) {
            return GrantSet::superAllowed();
        }

        if ($override === Effect::Deny) {
            return GrantSet::superDenied();
        }

        $subjects = $this->drivers->subjectsFor($driver, $actor, $scope);

        // ── H.2 steps 2 + 3: the ACL allow-mask + deny-mask from the bounded walk.
        [$aclAllowBits, $deniedBits, $aclLevel] = $this->resolveAcl(node: $node, subjects: $subjects);

        // ── H.2 step 4: granted = max(aclAllow, roleAllow). The role lane can RAISE
        // the allow-mask but it is folded BEFORE the deny veto, so it cannot
        // resurrect a denied bit.
        [$roleAllowBits, $roleLevel, $roleDeniedBits] = $this->resolveRole(
            driver: $driver,
            actor: $actor,
            scope: $scope,
            subjects: $subjects,
        );

        $allowedBits = $aclAllowBits | $roleAllowBits;
        $deniedBits |= $roleDeniedBits;
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
     * @param  iterable<Node>          $nodes
     * @return array<string, GrantSet>
     */
    public function evaluateMany(Authorizable $actor, iterable $nodes): array
    {
        $nodes = new Collection(is_array($nodes) ? $nodes : iterator_to_array($nodes));
        if ($nodes->isEmpty()) {
            return [];
        }
        $nodes->loadMissing('storage');
        $groups = [];
        $drivers = [];
        foreach ($nodes as $node) {
            $drive = $this->storageOf($node);
            $driver = $this->drivers->forProfile($drive->profile);
            $key = json_encode([$drive->id, $drive->profile, $driver::class, $node->owner_id], JSON_THROW_ON_ERROR);
            $groups[$key][] = $node;
            $drivers[$key] = $driver;
        }
        $sets = [];
        foreach ($groups as $key => $group) {
            $sets += $this->evaluateGroup($actor, $group, $drivers[$key]);
        }

        return $sets;
    }

    /** @param list<Node> $nodes
     * @return array<string, GrantSet>
     */
    private function evaluateGroup(Authorizable $actor, array $nodes, PermissionDriver $driver): array
    {
        $groups = [];
        foreach ($nodes as $node) {
            $key = json_encode([Identity::stringify($node->drive_id), $node->owner_id === null ? null : Identity::stringify($node->owner_id)], JSON_THROW_ON_ERROR);
            $groups[$key][] = $node;
        }
        $sets = [];
        $pending = [];
        $scopes = [];
        foreach ($groups as $key => $group) {
            $scope = $this->scopeFor($this->storageOf($group[0]), $group[0]);
            $override = $driver->override($actor, $scope);
            if ($override !== null) {
                foreach ($group as $node) {
                    $sets[Identity::stringify($node->id)] = $override === Effect::Allow ? GrantSet::superAllowed() : GrantSet::superDenied();
                }

                continue;
            }
            $scopes[$key] = $scope;
            foreach ($group as $node) {
                $pending[] = [$node, $key];
            }
        }
        if ($pending === []) {
            return $sets;
        }
        $subjects = $this->drivers->subjectsFor($driver, $actor, $this->scopeFor($this->storageOf($nodes[0]), $nodes[0]));
        $roles = [];
        foreach ($scopes as $key => $scope) {
            $roles[$key] = $this->resolveRole($driver, $actor, $scope, $subjects);
        }
        $parentIds = [];
        $ids = [];
        foreach ($pending as [$node]) {
            $ids[] = $node->id;
            if ($node->inherit_grants && $node->parent_id !== null) {
                $parentIds[] = $node->parent_id;
            }
        }
        $chains = $subjects === [] ? [] : app(NodeReadRepository::class)->ancestorChains(array_values(array_unique($parentIds)));
        foreach ($chains as $chain) {
            foreach ($chain as $row) {
                if (!is_int($row->id) && !is_string($row->id)) {
                    throw new \LogicException('Invalid ancestor identity.');
                }
                $ids[] = $row->id;
            }
        }
        $byNode = [];
        if ($subjects !== []) {
            foreach ($this->grants->forSubjectsOnNodes(array_values(array_unique($ids)), $subjects, Carbon::now()) as $grant) {
                $byNode[Identity::stringify($grant->node_id)][] = $grant;
            }
        }
        $folder = new AclFolder;
        $inherited = [];
        foreach ($chains as $origin => $chain) {
            $depths = [];
            foreach ($chain as $row) {
                $depths[] = $this->grantMask($byNode[Identity::stringify($row->id)] ?? [], true);
            }
            $inherited[$origin] = $folder->foldChain($depths);
        }
        foreach ($pending as [$node, $key]) {
            $parentKey = Identity::stringify($node->parent_id);
            $chain = $chains[$parentKey] ?? [];
            $sameStorage = $chain !== [] && Identity::stringify($chain[0]->drive_id) === Identity::stringify($node->drive_id);
            $parent = $node->inherit_grants && $sameStorage ? $inherited[$parentKey] : AclMask::none();
            $own = $this->grantMask($byNode[Identity::stringify($node->id)] ?? [], false);
            $mask = $this->vaulterConfig->featureEnabled('grants', $this->storageOf($node)->profile)
                ? $folder->applyOwn($parent, $own['allow'], $own['deny'], $own['level'])
                : AclMask::none();
            [$roleAllow, $roleLevel, $roleDenied] = $roles[$key];
            $sets[Identity::stringify($node->id)] = new GrantSet(allowedBits: $mask->allowed | $roleAllow, deniedBits: $mask->denied | $roleDenied, level: $this->maxLevel($mask->level, $roleLevel));
        }

        return $sets;
    }

    /** @param list<NodeGrant> $grants
     * @return array{allow:int, deny:int, level:?GrantLevel}
     */
    private function grantMask(array $grants, bool $inherited): array
    {
        $allow = 0;
        $deny = 0;
        $level = null;
        foreach ($grants as $grant) {
            if ($inherited && !$grant->scope->inheritsDownward()) {
                continue;
            }
            if ($grant->effect === Effect::Allow) {
                $allow |= $this->bitsFor($grant);
                $level = $this->maxLevel($level, $grant->level);
            } else {
                $deny |= $this->bitsFor($grant);
            }
        }

        return ['allow' => $allow, 'deny' => $deny, 'level' => $level];
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
     * DIAGNOSTIC "explain access" (F-P1.1-02 / S1.7): why does (actor, ability, node)
     * resolve the way it does? Returns the verdict, a machine-readable reason, the
     * folded allow/deny masks + level, and the provenance chain of every grant the
     * bounded walk visited (nearest-first, each tagged with its depth and whether it
     * counted). This runs a SEPARATE provenance-collecting pass and is NEVER called
     * from can()/effectiveGrants(), so the hot authorization path stays allocation-free
     * of this bookkeeping.
     */
    public function explain(Authorizable $actor, Ability $ability, Node $node): AccessExplanation
    {
        if (app(TenancyBoundary::class)->enforced()) {
            app(TenancyBoundary::class)->assertNode($node);
            $node = $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
        }
        $storage = $this->storageOf($node);
        $driver = $this->drivers->forProfile($storage->profile);
        $scope = $this->scopeFor($storage, $node);

        $override = $driver->override($actor, $scope);

        if ($override === Effect::Allow) {
            return new AccessExplanation(
                allowed: true,
                ability: $ability,
                reason: AccessExplanation::REASON_SUPER_ALLOW,
                allowedBits: 0,
                deniedBits: 0,
                level: GrantLevel::Owner,
                aces: [],
            );
        }

        if ($override === Effect::Deny) {
            return new AccessExplanation(
                allowed: false,
                ability: $ability,
                reason: AccessExplanation::REASON_SUPER_DENY,
                allowedBits: 0,
                deniedBits: 0,
                level: null,
                aces: [],
            );
        }

        $subjects = $this->drivers->subjectsFor($driver, $actor, $scope);

        [$aclAllowBits, $deniedBits, $aclLevel, $aces] = $this->resolveAclWithProvenance(
            node: $node,
            subjects: $subjects,
        );

        [$roleAllowBits, $roleLevel, $roleDeniedBits] = $this->resolveRole(
            driver: $driver,
            actor: $actor,
            scope: $scope,
            subjects: $subjects,
        );

        $allowedBits = $aclAllowBits | $roleAllowBits;
        $deniedBits |= $roleDeniedBits;
        $level = $this->maxLevel($aclLevel, $roleLevel);

        // Fold through the SAME GrantSet the hot path uses so the explained verdict
        // can never diverge from can()'s.
        $verdict = new GrantSet(allowedBits: $allowedBits, deniedBits: $deniedBits, level: $level);
        $allowed = $verdict->allows($ability);

        return new AccessExplanation(
            allowed: $allowed,
            ability: $ability,
            reason: $this->explainReason($allowed, $ability, $deniedBits),
            allowedBits: $allowedBits,
            deniedBits: $deniedBits,
            level: $level,
            aces: $aces,
        );
    }

    /**
     * May the actor create nodes in the storage's workspace? Delegates to the
     * host-role driver's workspace gate (the folder/upload create path has no
     * existing node to resolve against). canCreate for folders/uploads (§6).
     */
    public function canCreateIn(Authorizable $actor, Drive $drive): bool
    {
        if (app(TenancyConfig::class)->enabled()) {
            $drive = app(TenancyBoundary::class)->assertDrive($drive);
        }

        return $this->drivers->forProfile($drive->profile)->canCreateIn(
            actor: $actor,
            scope: AccessScope::forDrive($drive),
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
        $ids = [];
        $driveModel = $this->vaulterConfig->model('drive');
        $nodeModel = $this->vaulterConfig->model('node');
        foreach (app(TenancyBoundary::class)->constrain($driveModel::query())->cursor() as $drive) {
            if (!$this->vaulterConfig->featureEnabled('grants', $drive->profile)) {
                continue;
            }
            $driver = $this->drivers->forProfile($drive->profile);
            $subjects = $this->drivers->subjectsFor($driver, $actor, AccessScope::forDrive($drive));
            $candidates = $this->grants->nodeIdsSharedWith(subjects: $subjects, now: Carbon::now());
            foreach (array_chunk($candidates, 500) as $chunk) {
                foreach ($nodeModel::query()->where('drive_id', $drive->id)->whereKey($chunk)->pluck('id') as $id) {
                    if (!is_int($id) && !is_string($id)) {
                        throw new \LogicException('Invalid shared node identity.');
                    }
                    $ids[] = $id;
                }
            }
        }

        return $ids;
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
     * =false) node sits at the FARTHEST depth of the bounded walk, so no grant ABOVE
     * it contributes — but a nearer explicit allow BELOW it still pierces that deny
     * (ADR-0007: a nearer allow beats a farther inherited deny; the wall gets no
     * exception).
     *
     * @param  list<Subject>                             $subjects
     * @return array{0: int, 1: int, 2: GrantLevel|null}
     */
    private function resolveAcl(Node $node, array $subjects): array
    {
        if ($subjects === [] || !$this->vaulterConfig->featureEnabled('grants', $this->storageOf($node)->profile)) {
            return [0, 0, null];
        }

        // The bounded ancestor chain, nearest-first: node, parent, …, root. The
        // staudenmeir ancestor relation is storage-scoped already (the tree lives
        // in one drive_id), so the walk never crosses workspaces.
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
            // already counted; a deny on this wall is only shielded from FARTHER
            // (above-wall) allows — the walk stops here so none exist — while a
            // nearer allow BELOW the wall still pierces it (ADR-0007, no wall exception).
            if (!$current->inherit_grants) {
                break;
            }
        }

        $mask = $this->foldDepths($chain, $allowByDepth, $denyByDepth, $level);

        return [$mask->allowed, $mask->denied, $mask->level];
    }

    /**
     * A provenance-collecting twin of resolveAcl() serving explain() (F-P1.1-02). It
     * folds the SAME allow/deny masks by the SAME rules (per-depth bucket, then a deny
     * pierced only by a strictly-nearer allow), but additionally records every visited
     * grant as an {@see AccessAce} — tagged with its walk depth and whether it counted
     * (an item-scoped ancestor grant is recorded with counted=false). It is kept apart
     * from resolveAcl() ON PURPOSE so the hot path carries no diagnostic bookkeeping.
     *
     * @param  list<Subject>                                                 $subjects
     * @return array{0: int, 1: int, 2: GrantLevel|null, 3: list<AccessAce>}
     */
    private function resolveAclWithProvenance(Node $node, array $subjects): array
    {
        if ($subjects === [] || !$this->vaulterConfig->featureEnabled('grants', $this->storageOf($node)->profile)) {
            return [0, 0, null, []];
        }

        $chain = $this->ancestorChain($node);

        $nodeIds = array_map(static fn (Node $n): int|string => $n->id, $chain);

        $now = Carbon::now();
        $grants = $this->grants->forSubjectsOnNodes(nodeIds: $nodeIds, subjects: $subjects, now: $now);

        /** @var array<string, list<NodeGrant>> $byNode */
        $byNode = [];

        foreach ($grants as $grant) {
            $byNode[Identity::stringify($grant->node_id)][] = $grant;
        }

        /** @var array<int, int> $allowByDepth */
        $allowByDepth = [];
        /** @var array<int, int> $denyByDepth */
        $denyByDepth = [];
        /** @var list<AccessAce> $aces */
        $aces = [];
        $level = null;

        foreach ($chain as $depth => $current) {
            $isSelf = $depth === 0;
            $rows = $byNode[Identity::stringify($current->id)] ?? [];

            foreach ($rows as $grant) {
                // A grant on an ancestor only inherits down when scope=subtree; the
                // node's own grants always apply. A non-counting row is still recorded
                // (counted=false) so the operator sees it was present but inert.
                $counted = $isSelf || $grant->scope->inheritsDownward();
                $bits = $this->bitsFor($grant);

                $aces[] = new AccessAce(
                    nodeId: $grant->node_id,
                    depth: $depth,
                    effect: $grant->effect,
                    scope: $grant->scope,
                    bits: $bits,
                    counted: $counted,
                );

                if (!$counted) {
                    continue;
                }

                if ($grant->effect === Effect::Allow) {
                    $allowByDepth[$depth] = ($allowByDepth[$depth] ?? 0) | $bits;
                    $level = $this->maxLevel($level, $grant->level);

                    continue;
                }

                $denyByDepth[$depth] = ($denyByDepth[$depth] ?? 0) | $bits;
            }

            if (!$current->inherit_grants) {
                break;
            }
        }

        $mask = $this->foldDepths($chain, $allowByDepth, $denyByDepth, $level);

        return [$mask->allowed, $mask->denied, $mask->level, $aces];
    }

    /**
     * The machine-readable reason for an explain() verdict: an allow, a deny-veto
     * (a required bit landed in the deny-mask), or simply no covering grant.
     */
    /**
     * @param list<Node>      $chain
     * @param array<int, int> $allows
     * @param array<int, int> $denies
     */
    private function foldDepths(array $chain, array $allows, array $denies, ?GrantLevel $level): AclMask
    {
        $depths = [];
        foreach ($chain as $depth => $current) {
            $depths[] = ['allow' => $allows[$depth] ?? 0, 'deny' => $denies[$depth] ?? 0, 'level' => $level];
            if (!$current->inherit_grants) {
                break;
            }
        }

        return (new AclFolder)->foldChain($depths);
    }

    private function explainReason(bool $allowed, Ability $ability, int $deniedBits): string
    {
        if ($allowed) {
            return AccessExplanation::REASON_ALLOW;
        }

        if (($deniedBits & $ability->requiredBits()) !== 0) {
            return AccessExplanation::REASON_DENY_VETO;
        }

        return AccessExplanation::REASON_NO_GRANT;
    }

    /**
     * H.2 step 4 input — the host-role allow-mask + level. A driver Decision RAISES
     * access (roleAllow); a driver-returned deny is folded the same way the ACL
     * deny path is, but per H.2 the role lane primarily contributes allows (the
     * final veto, computed from ACL deny, is what a role can never override).
     *
     * @param  list<Subject>                             $subjects
     * @return array{0: int, 1: GrantLevel|null, 2: int}
     */
    private function resolveRole(
        PermissionDriver $driver,
        Authorizable $actor,
        AccessScope $scope,
        array $subjects,
    ): array {
        $bits = 0;
        $level = null;
        $denied = 0;

        foreach ($subjects as $subject) {
            $decision = $driver->resolve($subject, $actor, $scope);

            if (!$decision instanceof Decision) {
                continue;
            }
            if ($decision->isDeny()) {
                $denied |= $decision->bits;

                continue;
            }

            $bits |= $decision->bits;
            $level = $this->maxLevel($level, $decision->level);
        }

        return [$bits, $level, $denied];
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
     * Runs under withMaxDepth(NodeReadRepository::DEFAULT_MAX_DEPTH) — F-P4.2-01,
     * VLT-REMED P4.3: this method executes on EVERY authorization check
     * (can()/effectiveGrants()), so an unbounded recursive-CTE ancestor walk pays
     * its cost on every request and, on SQLite, can hit
     * SQLITE_MAX_RECURSION_DEPTH on a deep tree. ADR-0005 requires the same cap
     * symmetrically on both traversal directions; descendants()/subtree() already
     * carry it (NodeReadRepository), this was the missing ascend-side half.
     *
     * @return list<Node>
     */
    private function ancestorChain(Node $node): array
    {
        $chain = [$node];

        $model = $this->vaulterConfig->model('node');

        /** @var Collection<int, Node> $ancestors */
        $ancestors = $model::withMaxDepth(
            NodeReadRepository::DEFAULT_MAX_DEPTH,
            static fn (): Collection => $node->ancestors()->get(),
        );
        if (app(TenancyConfig::class)->enabled()) {
            app(TenancyBoundary::class)->assertTree($ancestors, $this->storageOf($node));
        }

        // ancestors() returns nearest-first already; bound it to the same storage so
        // a misconfigured tree can never escape the workspace (defence-in-depth on
        // top of the storage-scoped relation).
        foreach ($ancestors as $ancestor) {
            if ($ancestor->drive_id !== $node->drive_id) {
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

    private function scopeFor(Drive $storage, Node $node): AccessScope
    {
        $owner = $node->owner_id === null
            ? null
            : new Subject(type: $storage->owner_type, id: $node->owner_id);

        return AccessScope::forDrive($storage, $owner);
    }

    private function storageOf(Node $node): Drive
    {
        if (app(TenancyBoundary::class)->enforced()) {
            return app(TenancyBoundary::class)->assertNode($node);
        }
        // Prefer an already-loaded storage relation (e.g. sharedWith eager-loads it)
        // so a bulk re-resolution does NOT fire one storage query per node; fall back
        // to the read repository for a node loaded without it.
        $storage = $node->relationLoaded('storage')
            ? $node->getRelation('storage')
            : $this->storages->find($node->drive_id);

        if (!$storage instanceof Drive) {
            // A node always belongs to a storage (FK cascade); a missing storage is
            // a corrupt state — deny by surfacing an empty workspace scope is unsafe,
            // so we fail loud rather than silently open.
            throw new \RuntimeException(sprintf(
                'Vaulter could not resolve the storage [%s] for node [%s].',
                Identity::stringify($node->drive_id),
                Identity::stringify($node->id),
            ));
        }

        return $storage;
    }
}
