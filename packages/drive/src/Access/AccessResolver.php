<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Drive\Access\ValueObjects\AccessExplanation;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\AccessSource;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\GrantSet;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\Effect;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\Access\GrantReadRepository;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;

/**
 * The Vaulter-OWNED, NON-swappable effective-access engine. It combines the
 * v_node_grants rows with the pluggable {@see PermissionDriver} and is the single
 * authority every guard, listing and resource asks. Only the host-role lane (the
 * driver) is swappable.
 *
 * THE ALGORITHM:
 *   1. Host override — Allow is a super-user bypass, Deny an administrative block;
 *      both are absolute and skip the walk.
 *   2. Walk the node and its ancestors nearest-first within the drive, collecting
 *      the live grants of the subject-set: every grant on the node itself, and the
 *      subtree grants of ancestors. The walk stops after the first node whose
 *      inherit_grants is false: its own grants still apply, nothing above it does.
 *   3. Effective abilities = the union of the roles of those grants and of the
 *      PermissionDriver's decisions. Grants are positive only, so adding a grant or
 *      a group membership never takes access away, and there is no rank.
 *
 * Expiry is read-time (filtered in the grant query). The ancestor walk is bounded
 * to the drive and to NodeReadRepository::DEFAULT_MAX_DEPTH.
 */
#[Internal]
final class AccessResolver
{
    public function __construct(
        private readonly DriveConfig $vaulterConfig,
        private readonly PermissionDriverManager $drivers,
        private readonly GrantReadRepository $grants,
        private readonly DriveReadRepository $storages,
        private readonly TenancyConfig $tenancy,
        private readonly TenancyBoundary $boundary,
    ) {}

    /**
     * May the actor perform the ability on the node?
     */
    public function can(Authorizable $actor, Ability $ability, Node $node): bool
    {
        return $this->effectiveGrants($actor, $node)->allows($ability);
    }

    /**
     * The effective abilities of (actor, node); can()/abilitiesFor() read it.
     */
    public function effectiveGrants(Authorizable $actor, Node $node): GrantSet
    {
        $node = $this->fresh($node);
        $storage = $this->storageOf($node);
        $driver = $this->drivers->forProfile($storage->profile);
        $scope = AccessScope::forDrive($storage);

        $override = $driver->override($actor, $scope);
        if ($override === Effect::Allow) {
            return GrantSet::superAllowed();
        }
        if ($override === Effect::Deny) {
            return GrantSet::superDenied();
        }

        $subjects = $this->drivers->subjectsFor($driver, $actor, $scope);
        $bits = 0;
        foreach ($this->applicableGrants($node, $subjects)['grants'] as [$grant]) {
            $bits |= $grant->role()->bits;
        }
        foreach ($this->decisions($driver, $actor, $scope, $subjects) as [, $decision]) {
            $bits |= $decision->role->bits;
        }

        return new GrantSet(allowedBits: $bits);
    }

    /**
     * The same answer as effectiveGrants() for many nodes, with one grant query and
     * one ancestor query per drive group.
     *
     * @param  iterable<Node>          $nodes
     * @return array<string, GrantSet>
     */
    public function evaluateMany(Authorizable $actor, iterable $nodes): array
    {
        $nodes = new Collection(is_array($nodes) ? $nodes : iterator_to_array($nodes));
        if ($nodes->isEmpty()) {
            return [];
        }
        // Under strict tenancy each node's drive is checked against the tenant: once for the
        // whole batch, not three reads per node. Otherwise the eager-loaded relation serves.
        $checked = $this->boundary->enforced() ? $this->boundary->assertNodes(array_values($nodes->all())) : null;
        if ($checked === null) {
            $nodes->loadMissing('storage');
        }
        $groups = [];
        $drives = [];
        foreach ($nodes as $node) {
            $drive = $checked === null ? $this->storageOf($node) : $checked[Identity::stringify($node->getRawOriginal($node->getKeyName()))];
            $groups[Identity::stringify($drive->id)][] = $node;
            $drives[Identity::stringify($drive->id)] = $drive;
        }
        $sets = [];
        foreach ($groups as $driveKey => $group) {
            $sets += $this->evaluateGroup($actor, $group, $drives[$driveKey]);
        }

        return $sets;
    }

    /** @param list<Node> $nodes
     * @return array<string, GrantSet>
     */
    private function evaluateGroup(Authorizable $actor, array $nodes, Drive $drive): array
    {
        $driver = $this->drivers->forProfile($drive->profile);
        $scope = AccessScope::forDrive($drive);
        $override = $driver->override($actor, $scope);
        if ($override !== null) {
            $set = $override === Effect::Allow ? GrantSet::superAllowed() : GrantSet::superDenied();

            return array_fill_keys(array_map(static fn (Node $node): string => Identity::stringify($node->id), $nodes), $set);
        }
        $subjects = $this->drivers->subjectsFor($driver, $actor, $scope);
        $roleBits = 0;
        foreach ($this->decisions($driver, $actor, $scope, $subjects) as [, $decision]) {
            $roleBits |= $decision->role->bits;
        }
        if ($subjects === [] || !$this->vaulterConfig->featureEnabled('grants', $drive->profile)) {
            return array_fill_keys(array_map(static fn (Node $node): string => Identity::stringify($node->id), $nodes), new GrantSet(allowedBits: $roleBits));
        }

        $parentIds = [];
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->id;
            if ($node->inherit_grants && $node->parent_id !== null) {
                $parentIds[] = $node->parent_id;
            }
        }
        $chains = app(NodeReadRepository::class)->ancestorChains(array_values(array_unique($parentIds)));
        foreach ($chains as $chain) {
            foreach ($chain as $row) {
                if (!is_int($row->id) && !is_string($row->id)) {
                    throw new \LogicException('Invalid ancestor identity.');
                }
                $ids[] = $row->id;
            }
        }
        $byNode = [];
        foreach ($this->grants->forSubjectsOnNodes(array_values(array_unique($ids)), $subjects, Carbon::now()) as $grant) {
            $byNode[Identity::stringify($grant->node_id)][] = $grant;
        }

        // The inherited bits of a parent chain: subtree grants up to the boundary.
        $inherited = [];
        foreach ($chains as $origin => $chain) {
            $bits = 0;
            foreach ($chain as $row) {
                $bits |= $this->bitsOf($byNode[Identity::stringify($row->id)] ?? [], subtreeOnly: true);
            }
            $inherited[$origin] = $bits;
        }

        $sets = [];
        foreach ($nodes as $node) {
            $parentKey = Identity::stringify($node->parent_id);
            $chain = $chains[$parentKey] ?? [];
            $sameDrive = $chain !== [] && Identity::stringify($chain[0]->drive_id) === Identity::stringify($node->drive_id);
            $bits = $this->bitsOf($byNode[Identity::stringify($node->id)] ?? [], subtreeOnly: false)
                | ($node->inherit_grants && $sameDrive ? $inherited[$parentKey] : 0);
            $sets[Identity::stringify($node->id)] = new GrantSet(allowedBits: $bits | $roleBits);
        }

        return $sets;
    }

    /** @param list<NodeGrant> $grants */
    private function bitsOf(array $grants, bool $subtreeOnly): int
    {
        $bits = 0;
        foreach ($grants as $grant) {
            if (!$subtreeOnly || $grant->scope->inheritsDownward()) {
                $bits |= $grant->role()->bits;
            }
        }

        return $bits;
    }

    /**
     * The UI ability flags for (actor, node).
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
     * The UI ability flags for a node known by its snapshot; all false when the node is gone.
     *
     * @return array<string, bool>
     */
    public function abilitiesForSnapshot(Authorizable $actor, NodeSnapshot $snapshot): array
    {
        $class = $this->vaulterConfig->model('node');
        $node = $class::query()->find($snapshot->id);

        return $node instanceof Node ? $this->abilitiesFor($actor, $node) : NodeAbilities::denied()->toArray();
    }

    /**
     * Why does (actor, ability, node) resolve the way it does? Lists every role that
     * applies with its origin, the administrative restriction in force and the
     * inheritance boundary that stopped the walk. A separate pass from can(), built
     * from the same applicable-grant selection, so the verdicts cannot diverge.
     */
    public function explain(Authorizable $actor, Ability $ability, Node $node): AccessExplanation
    {
        $node = $this->fresh($node);
        $storage = $this->storageOf($node);
        $driver = $this->drivers->forProfile($storage->profile);
        $scope = AccessScope::forDrive($storage);

        $override = $driver->override($actor, $scope);
        if ($override === Effect::Allow) {
            return new AccessExplanation(allowed: true, ability: $ability, reason: AccessExplanation::REASON_OVERRIDE_ALLOW, sources: [], restrictions: [], boundaryNodeId: null);
        }
        if ($override === Effect::Deny) {
            return new AccessExplanation(allowed: false, ability: $ability, reason: AccessExplanation::REASON_OVERRIDE_DENY, sources: [],
                restrictions: [AccessExplanation::RESTRICTION_OVERRIDE_DENY], boundaryNodeId: null);
        }

        $subjects = $this->drivers->subjectsFor($driver, $actor, $scope);
        $applicable = $this->applicableGrants($node, $subjects);
        $sources = [];
        $bits = 0;
        foreach ($applicable['grants'] as [$grant, $inherited]) {
            $role = $grant->role();
            $bits |= $role->bits;
            $sources[] = new AccessSource(grantId: $grant->id, subject: $grant->subject(), role: $role->key, roleVersion: $role->version,
                originNodeId: $grant->node_id, inherited: $inherited, expiresAt: $grant->expires_at);
        }
        foreach ($this->decisions($driver, $actor, $scope, $subjects) as [$subject, $decision]) {
            $bits |= $decision->role->bits;
            $sources[] = new AccessSource(grantId: null, subject: $subject, role: $decision->role->key, roleVersion: $decision->role->version,
                originNodeId: null, inherited: false, expiresAt: null);
        }
        $allowed = (new GrantSet(allowedBits: $bits))->allows($ability);

        return new AccessExplanation(
            allowed: $allowed,
            ability: $ability,
            reason: $allowed ? AccessExplanation::REASON_ALLOW : AccessExplanation::REASON_NO_GRANT,
            sources: $sources,
            restrictions: [],
            boundaryNodeId: $applicable['boundary'],
        );
    }

    /**
     * May the actor create nodes in the drive? Delegates to the host-role driver's
     * workspace gate (the folder/upload create path also needs Create on the parent).
     */
    public function canCreateIn(Authorizable $actor, Drive $drive): bool
    {
        if ($this->tenancy->enabled()) {
            $drive = $this->boundary->assertDrive($drive);
        }

        return $this->drivers->forProfile($drive->profile)->canCreateIn(
            actor: $actor,
            scope: AccessScope::forDrive($drive),
        );
    }

    /**
     * The "shared with me" view: the nodes an actor holds a grant on,
     * re-resolved through the full algorithm. The fast subject-index pass yields
     * candidate node ids; each candidate is then re-checked with can(View), because
     * a previewer-free role, a host override or a disabled feature may still hide it.
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
     * The candidate node ids an actor has a live grant on — the fast
     * subject-index pass behind filterSharedWith. The caller loads the nodes
     * and re-resolves them; this never decides visibility on its own.
     *
     * @return list<int|string>
     */
    public function sharedNodeIds(Authorizable $actor): array
    {
        $ids = [];
        $driveModel = $this->vaulterConfig->model('drive');
        $nodeModel = $this->vaulterConfig->model('node');
        foreach ($this->boundary->constrain($driveModel::query())->cursor() as $drive) {
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
     * The nodes of one drive the actor holds a live grant on. With the root out of
     * sight, only these and their subtrees can be visible: none means the actor sees
     * nothing in the drive. Candidates only; resolve each before relying on it.
     *
     * @internal
     *
     * @return list<int|string>
     */
    public function grantAnchorIdsIn(Authorizable $actor, Drive $drive): array
    {
        if ($this->tenancy->enabled()) {
            $drive = $this->boundary->assertDrive($drive);
        }
        if (!$this->vaulterConfig->featureEnabled('grants', $drive->profile)) {
            return [];
        }
        $driver = $this->drivers->forProfile($drive->profile);

        return $this->grants->nodeIdsGrantedIn(
            subjects: $this->drivers->subjectsFor($driver, $actor, AccessScope::forDrive($drive)),
            driveId: $drive->id,
            now: Carbon::now(),
        );
    }

    /**
     * The live grants of the subject-set that apply to the node, nearest first, each
     * with whether it was inherited, and the boundary node that ended the walk.
     *
     * @param  list<Subject>                                                                $subjects
     * @return array{grants: list<array{0: NodeGrant, 1: bool}>, boundary: int|string|null}
     */
    private function applicableGrants(Node $node, array $subjects): array
    {
        $chain = $this->ancestorChain($node);
        $boundary = null;
        foreach ($chain as $depth => $current) {
            if (!$current->inherit_grants) {
                $boundary = $current->id;
                $chain = array_slice($chain, 0, $depth + 1);

                break;
            }
        }
        if ($subjects === [] || !$this->vaulterConfig->featureEnabled('grants', $this->storageOf($node)->profile)) {
            return ['grants' => [], 'boundary' => $boundary];
        }

        $byNode = [];
        $ids = array_map(static fn (Node $n): int|string => $n->id, $chain);
        foreach ($this->grants->forSubjectsOnNodes(nodeIds: $ids, subjects: $subjects, now: Carbon::now()) as $grant) {
            $byNode[Identity::stringify($grant->node_id)][] = $grant;
        }
        $applicable = [];
        foreach ($chain as $depth => $current) {
            foreach ($byNode[Identity::stringify($current->id)] ?? [] as $grant) {
                // An ancestor's grant reaches down only when its scope is subtree.
                if ($depth === 0 || $grant->scope->inheritsDownward()) {
                    $applicable[] = [$grant, $depth > 0];
                }
            }
        }

        return ['grants' => $applicable, 'boundary' => $boundary];
    }

    /**
     * The PermissionDriver's role per subject.
     *
     * @param  list<Subject>                        $subjects
     * @return list<array{0: Subject, 1: Decision}>
     */
    private function decisions(PermissionDriver $driver, Authorizable $actor, AccessScope $scope, array $subjects): array
    {
        $decisions = [];
        foreach ($subjects as $subject) {
            $decision = $driver->resolve($subject, $actor, $scope);
            if ($decision instanceof Decision) {
                $decisions[] = [$subject, $decision];
            }
        }

        return $decisions;
    }

    /** Under strict tenancy the node is re-read so a forged model cannot be judged. */
    private function fresh(Node $node): Node
    {
        if (!$this->boundary->enforced()) {
            return $node;
        }
        $this->boundary->assertNode($node);

        return $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
    }

    /**
     * The bounded ancestor chain nearest-first: the node, then each ancestor up to
     * the drive root (the staudenmeir ancestor relation, drive-scoped).
     *
     * Runs under withMaxDepth(NodeReadRepository::DEFAULT_MAX_DEPTH): this executes
     * on every authorization check, so an unbounded recursive-CTE walk would pay its
     * cost on every request and, on SQLite, could hit SQLITE_MAX_RECURSION_DEPTH on a
     * deep tree (docs/adr/0005-unified-node-tree.md caps both traversal directions).
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
        if ($this->tenancy->enabled()) {
            $this->boundary->assertTree($ancestors, $this->storageOf($node));
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

    private function storageOf(Node $node): Drive
    {
        if ($this->boundary->enforced()) {
            return $this->boundary->assertNode($node);
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
