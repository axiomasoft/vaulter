<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Contracts\Access\GrantAuthoritativeDriver;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Repositories\Access\GrantReadRepository;
use Vaulter\Exceptions\Nodes\TreeTraversalIncompleteException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;
use Vaulter\Support\NodeName;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side queries over the v_nodes adjacency tree. Tree reads use
 * the staudenmeir recursive relationships (descendants/ancestors), always bounded
 * by treeOf(storage) and — for bulk descents — withMaxDepth, never the BANNED
 * whereHas('descendants') / withCount('descendants') which break on MariaDB and
 * degrade on Postgres (arch-ban).
 *
 * queryForUser()/constrainToAccessible() narrow grant-authoritative drivers to
 * candidate IDs. Candidates require exact AccessResolver evaluation before display;
 * the REST listings and admin table apply that after fetching each page.
 */
#[Internal]
final class NodeReadRepository
{
    /**
     * The maximum recursion depth for bulk subtree descents — file managers live
     * at depth ≤ ~20, so this caps a pathological tree without an explicit
     * caller bound.
     */
    public const int DEFAULT_MAX_DEPTH = 64;

    /**
     * Keep small sets bound, with room for storage, parent and cursor bindings even
     * on SQLite builds limited to 999 variables. Larger sets use escaped SQL literals
     * and add no bindings, regardless of the number of grant-reachable nodes.
     */
    private const int WHERE_IN_BIND_LIMIT = 900;

    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly AccessResolver $access,
        private readonly PermissionDriverManager $drivers,
        private readonly GrantReadRepository $grants,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function find(int|string $id): ?Node
    {
        $query = $this->query();

        return Identity::fitsKey($query->getModel(), $id) ? $query->find($id) : null;
    }

    /**
     * Every live (non-trashed, non-deleted) node in a storage's tree.
     *
     * @return Builder<Node>
     */
    public function forStorage(Drive $storage): Builder
    {
        if (app(TenancyConfig::class)->enabled()) {
            $storage = app(TenancyBoundary::class)->assertDrive($storage);
        }

        return $this->query()
            ->treeOf($storage->id)
            ->whereNull('trashed_at');
    }

    /**
     * Direct children of a folder (or the storage root when $parent is null),
     * ordered by name.
     *
     * @return Builder<Node>
     */
    public function children(Drive $storage, ?Node $parent): Builder
    {
        return $this->metrics->timing(
            name: 'vaulter_tree_query_duration_seconds',
            operation: fn (): Builder => $this->query()
                ->treeOf($storage->id)
                ->whereNull('trashed_at')
                ->where('parent_id', $parent?->id)
                ->orderBy('name')
                ->orderBy('id'),
            labels: [
                'query_kind' => 'children',
                'tier' => LoadTier::label(),
            ],
        );
    }

    /**
     * Whether the node is its drive's root — the one node without a parent, which
     * tree operations never take as an operand.
     */
    public function isRoot(Node $node): bool
    {
        $node = $this->scopedNode($node);
        $rootId = $node->storage->root_node_id;

        return $rootId !== null && Identity::stringify($node->getKey()) === Identity::stringify($rootId);
    }

    /**
     * The whole subtree below a node via the recursive CTE, bounded by
     * withMaxDepth so the descent never runs unbounded. The node itself is
     * NOT part of descendants() — callers add it when needed.
     *
     * @return Collection<int, Node>
     */
    public function descendants(Node $node, ?int $maxDepth = null): Collection
    {
        $node = $this->scopedNode($node);
        $model = $this->vaulterConfig->model('node');
        $bound = $maxDepth ?? $this->treeBound();

        /** @var Collection<int, Node> $result */
        $result = $model::withMaxDepth(
            $bound,
            static fn (): Collection => $node->descendants()->get(),
        );
        $this->assertComplete($result, $bound);

        return $this->checkedTree($result, $node);
    }

    /**
     * How deep a mutation traversal reads: the deepest tree any profile allows, never
     * less than DEFAULT_MAX_DEPTH. A tree that is still deeper (a policy lowered after
     * the fact, or legacy data) is detected by assertComplete(), not silently cut.
     */
    public function treeBound(): int
    {
        $bound = self::DEFAULT_MAX_DEPTH;
        foreach ($this->profiles()->all() as $profile) {
            $bound = max($bound, $profile->getDrivePolicy()->maxFolderDepth + 1);
        }

        return $bound;
    }

    /** Resolved per call: a registry swapped after boot must be the one these limits are read from. */
    private function profiles(): ProfileRegistry
    {
        return app(ProfileRegistry::class);
    }

    /**
     * Fail closed when the traversal stopped at its bound with rows still below: the
     * deepest level read must have no children (fixed probe by parent in chunks).
     *
     * @param Collection<int, Node> $nodes
     */
    private function assertComplete(Collection $nodes, int $bound): void
    {
        $edge = [];
        foreach ($nodes as $node) {
            $depth = $node->getAttribute('depth');
            if (is_numeric($depth) && (int) $depth >= $bound - 1) {
                $edge[] = $node->getKey();
            }
        }
        if ($edge === []) {
            return;
        }
        $model = $this->vaulterConfig->model('node');
        foreach (array_chunk($edge, self::WHERE_IN_BIND_LIMIT) as $chunk) {
            if ($model::query()->whereIn('parent_id', $chunk)->exists()) {
                throw TreeTraversalIncompleteException::forBound($bound);
            }
        }
    }

    /**
     * The node plus its whole subtree (the node first), bounded by withMaxDepth.
     * This is the unit a trash/restore/force-delete touches atomically.
     *
     * @return Collection<int, Node>
     */
    public function subtree(Node $node, ?int $maxDepth = null): Collection
    {
        $node = $this->scopedNode($node);

        return $this->metrics->timing(
            name: 'vaulter_tree_query_duration_seconds',
            operation: function () use ($node, $maxDepth): Collection {
                $model = $this->vaulterConfig->model('node');

                $bound = $maxDepth ?? $this->treeBound();

                /** @var Collection<int, Node> $result */
                $result = $model::withMaxDepth(
                    $bound,
                    static fn (): Collection => $node->descendantsAndSelf()->get(),
                );
                $this->assertComplete($result, $bound);

                return $this->checkedTree($result, $node);
            },
            labels: [
                'query_kind' => 'subtree',
                'tier' => LoadTier::label(),
            ],
        );
    }

    /**
     * Every child row of a folder, trashed ones included: they count against the
     * drive policy's maxFolderChildren until purged.
     */
    public function childCount(int|string $parentId): int
    {
        return $this->query()->where('parent_id', $parentId)->count();
    }

    /** Levels between the node and its drive root: the root is 0, its children 1. */
    public function depth(Node $node): int
    {
        $node = $this->scopedNode($node);

        return $node->ancestors()->count();
    }

    /**
     * Levels below the node in its subtree, trashed members included: 0 for a leaf.
     * Read at most $bound levels deep, so a deeper subtree reports $bound.
     */
    public function height(Node $node, int $bound): int
    {
        $node = $this->scopedNode($node);
        $model = $this->vaulterConfig->model('node');

        $height = $model::withMaxDepth($bound, static fn (): mixed => $node->descendants()->max('depth'));

        return is_numeric($height) ? (int) $height : 0;
    }

    /**
     * @param  list<int|string>               $parentIds
     * @return array<string, list<\stdClass>>
     */
    public function ancestorChains(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        /** @var \Staudenmeir\LaravelAdjacencyList\Eloquent\Builder<Node> $query */
        $query = $this->query();
        $connection = app(VaulterDatabase::class)->connection();
        $grammar = $query->getExpressionGrammar();
        $table = $connection->getQueryGrammar()->wrapTable($query->getModel()->getTable());
        $separator = $query->getModel()->getPathSeparator();
        $initialPath = $grammar->compileInitialPath('id', '__acl_path');
        $seed = $this->whereKeyInBounded($query, $parentIds)
            ->select(['id', 'parent_id', 'drive_id', 'inherit_grants'])->selectRaw('id as origin, 0 as depth');
        $strict = app(TenancyConfig::class)->enabled();
        if ($strict) {
            $seed->addSelect('tenant_id');
        }
        // Match ancestors()'s ascend semantics, including its database-specific
        // cycle guard. withMaxDepth(+64) does not limit negative ancestor depths.
        $path = $grammar->compileRecursivePath('n.id', 'c.__acl_path');
        $cycle = $grammar->compileCycleDetection('n.id', 'c.__acl_path');
        $sql = 'WITH RECURSIVE chain AS (SELECT seed.*, '.$initialPath.' FROM ('.$seed->toSql().') seed
            UNION ALL SELECT n.id, n.parent_id, n.drive_id, n.inherit_grants, c.origin, c.depth + 1'.($strict ? ', n.tenant_id' : '').', '.$path.'
            FROM '.$table.' n JOIN chain c ON n.id = c.parent_id AND n.drive_id = c.drive_id
            WHERE c.inherit_grants = ? AND NOT ('.$cycle.'))
            SELECT * FROM chain ORDER BY origin, depth';
        $rows = $connection->select($sql, [
            ...$seed->getBindings(),
            ...$grammar->getRecursivePathBindings($separator),
            true,
            ...$grammar->getCycleDetectionBindings($separator),
        ]);
        $chains = [];
        $byDrive = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass || (!is_string($row->origin) && !is_int($row->origin))) {
                throw new \LogicException('Invalid ancestor chain row.');
            }
            $chains[Identity::stringify($row->origin)][] = $row;
            if ($strict) {
                if (!is_int($row->drive_id) && !is_string($row->drive_id)) {
                    throw new \LogicException('Invalid ancestor drive key.');
                }
                $byDrive[Identity::stringify($row->drive_id)][] = $row;
            }
        }
        if ($strict) {
            $driveClass = $this->vaulterConfig->model('drive');
            foreach ($byDrive as $driveId => $nodes) {
                $drive = $driveClass::query()->findOrFail($driveId);
                app(TenancyBoundary::class)->assertTree($nodes, $drive);
            }
        }

        return $chains;
    }

    /**
     * The ancestor chain from the node up to the storage root (nearest first),
     * bounded by withMaxDepth — the same cap
     * descendants()/subtree() already apply on the other traversal direction
     * (docs/adr/0005-unified-node-tree.md requires the symmetry; this was the missing ascend-side half).
     *
     * @return Collection<int, Node>
     */
    public function ancestors(Node $node): Collection
    {
        $node = $this->scopedNode($node);
        $model = $this->vaulterConfig->model('node');

        /** @var Collection<int, Node> $result */
        $result = $model::withMaxDepth(
            self::DEFAULT_MAX_DEPTH,
            static fn (): Collection => $node->ancestors()->get(),
        );

        return $this->checkedTree($result, $node);
    }

    /**
     * Breadcrumb from the storage root down to the node (root first), the node
     * included as the last crumb. Bounded by withMaxDepth — same
     * reasoning as ancestors() above.
     *
     * @return Collection<int, Node>
     */
    public function breadcrumb(Node $node): Collection
    {
        $node = $this->scopedNode($node);

        return $this->metrics->timing(
            name: 'vaulter_tree_query_duration_seconds',
            operation: function () use ($node): Collection {
                $model = $this->vaulterConfig->model('node');

                /** @var Collection<int, Node> $crumbs */
                $crumbs = $model::withMaxDepth(
                    self::DEFAULT_MAX_DEPTH,
                    static fn (): Collection => $node->ancestorsAndSelf()->get(),
                );
                $this->checkedTree($crumbs, $node);

                /** @var Collection<int, Node> $ordered */
                $ordered = $crumbs->reverse()->values();

                return $ordered;
            },
            labels: [
                'query_kind' => 'breadcrumb',
                'tier' => LoadTier::label(),
            ],
        );
    }

    /**
     * Name search within a storage tree (live nodes only). Matches a case-folded
     * substring of the node name — the search index backs this.
     *
     * The caller-supplied term is untrusted: `LIKE` metacharacters
     * (`%`, `_`, `\`) are escaped BEFORE the substring wildcards are wrapped
     * around it, with an explicit `ESCAPE` clause (the backslash is BOUND, not a literal: MySQL reads `'\'` as an escaped quote) — a raw `%`/`_` in the
     * term is matched LITERALLY, never as a wildcard, on SQLite, MySQL
     * and PostgreSQL. Case-folding happens first so escaping never touches
     * multibyte case-mapping. `orderBy('id')` is a stable tie-breaker
     * `name` is unique only per-parent (docs/adr/0005-unified-node-tree.md)
     * and this method searches across the WHOLE tree, so cursorPaginate()'s
     * keyset would otherwise skip/duplicate rows across a page boundary that
     * lands on two nodes sharing a name in different folders.
     *
     * @return Builder<Node>
     */
    public function search(Drive $storage, string $term): Builder
    {
        return $this->metrics->timing(
            name: 'vaulter_tree_query_duration_seconds',
            operation: function () use ($storage, $term): Builder {
                $escaped = addcslashes(mb_strtolower($term), '%_\\');

                return $this->query()
                    ->treeOf($storage->id)
                    ->whereNull('trashed_at')
                    ->whereRaw('lower(name) like ? escape ?', ['%'.$escaped.'%', '\\'])
                    ->orderBy('name')
                    ->orderBy('id');
            },
            labels: [
                'query_kind' => 'search',
                'tier' => LoadTier::label(),
            ],
        );
    }

    /**
     * The trash view: trashed (but not yet permanently deleted) nodes in a
     * storage, newest first. `orderByDesc('id')` is a stable tie-breaker
     * several nodes trashed by the same
     * operation/request share an identical trashed_at timestamp, so
     * cursorPaginate()'s keyset would otherwise skip/duplicate rows on that
     * page boundary.
     *
     * @return Builder<Node>
     */
    public function trashed(Drive $storage): Builder
    {
        return $this->query()
            ->treeOf($storage->id)
            ->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at')
            ->orderByDesc('id');
    }

    /**
     * The trash roots whose trashed_at is at or before $threshold — the purge-trash
     * candidates past their retention window. Only explicit
     * roots (trash_root_id = id) are returned: purging a root deletes its whole
     * subtree, including descendants trashed earlier on their own, so a member is
     * never purged twice. Returns a Builder so the command chunks it.
     *
     * @return Builder<Node>
     */
    public function trashedBefore(Carbon $threshold): Builder
    {
        return $this->trashRoots()
            ->where('trashed_at', '<=', $threshold)
            ->orderBy('trashed_at');
    }

    /**
     * Only the explicit roots of the trash: a node trashed with its folder is
     * listed, restored and purged through that folder.
     *
     * @return Builder<Node>
     */
    public function trashRoots(): Builder
    {
        $model = $this->vaulterConfig->model('node');
        $table = (new $model)->getTable();

        return $this->query()
            ->whereNotNull('trashed_at')
            ->whereColumn($table.'.trash_root_id', $table.'.id');
    }

    /**
     * Whether a live sibling already owns $name under $parentId in this storage
     * (same name_key: NFC + full case folding, compared byte for byte) — the
     * sibling-unique predicate the Actions check
     * before create/move/rename. $ignoreId excludes the node being renamed in
     * place. Uses a direct EXISTS, NOT a recursive descendant aggregate.
     */
    public function siblingNameExists(
        Drive $storage,
        int|string|null $parentId,
        string $name,
        int|string|null $ignoreId = null,
    ): bool {
        return $this->siblingQuery($storage, $parentId, $name, $ignoreId)->exists();
    }

    /** Resolve the same canonical predicate as siblingNameExists(). */
    public function findSibling(Drive $storage, int|string|null $parentId, string $name): ?Node
    {
        return $this->siblingQuery($storage, $parentId, $name)->first();
    }

    /** @return Builder<Node> */
    private function siblingQuery(Drive $storage, int|string|null $parentId, string $name, int|string|null $ignoreId = null): Builder
    {
        $query = $this->query()
            ->treeOf($storage->id)
            ->where('parent_id', $parentId)
            ->whereNull('trashed_at')
            ->where('name_key', NodeName::key($name));

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }
        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            // The caller holds the tree lock, but its transaction began earlier: a plain read on MySQL
            // (REPEATABLE READ) would answer from a snapshot older than that lock. A locking read sees
            // the committed state, as every read after the lock does on PostgreSQL.
            $query->sharedLock();
        }

        return $query;
    }

    /**
     * The STANDALONE public seam: the live (non-trashed) nodes an
     * actor may reach, as a Builder for pagination / admin. It is the named entry
     * for "give me this actor's nodes" = the live-nodes base + constrainToAccessible.
     * A caller that needs its own trashed-state control (the children cursor below, or
     * the admin table whose Trashed filter flips trashed_at) composes
     * {@see constrainToAccessible()} on its own base instead.
     *
     * When grant-filtering applies (see {@see grantFilteringApplies()}) the base is
     * constrained to the actor's CANDIDATE accessible node ids; otherwise the base is
     * returned UNCHANGED (open listing — the host's roles govern).
     *
     * The candidate set is the grant ANCHORS for the actor's subject-set UNION the
     * descendants of every SUBTREE-scoped anchor (an item-scoped anchor contributes
     * only its own node). It is a deliberate SUPERSET: it does NOT apply the
     * inherit_grants cut-off in SQL, so a node behind an inheritance boundary can
     * still be in the set. The candidate-set listing semantic is documented on
     * {@see constrainToAccessible()}; per-ACTION authorization stays EXACT via the
     * {@see AccessResolver} on every mutation/show.
     *
     * @return Builder<Node>
     */
    public function queryForUser(Authorizable $actor): Builder
    {
        $base = $this->query()->whereNull('trashed_at');

        return $this->constrainToAccessible($base, $actor);
    }

    /**
     * Constrain an ALREADY-BUILT node query to the actor's grant-reachable candidate
     * set, WITHOUT imposing the live/trashed filter — so a caller that owns
     * its own trashed-state handling (the children cursor; the admin table, whose
     * Trashed filter flips trashed_at) composes the access narrowing on top of its own
     * query. A no-op when grant-filtering does not apply (open drivers); forces an
     * empty result when it applies and the actor reaches no candidate.
     *
     * This SQL prefilter deliberately admits denied candidates. Apply exact batch
     * AccessResolver evaluation before display.
     *
     * Generic over the concrete model so a Node-typed base (the core listing paths) and
     * a admin resource's `Builder<Model>` both compose without a variance clash — the
     * narrowing is key-based, never model-specific.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function constrainToAccessible(Builder $query, Authorizable $actor): Builder
    {
        app(TenancyBoundary::class)->constrain($query);
        if (!$this->grantFilteringApplies()) {
            return $query;
        }

        $candidateIds = $this->candidateNodeIds($actor);

        if ($candidateIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->whereKeyInBounded($query, $candidateIds);
    }

    /**
     * Narrow an already-loaded set of nodes to the ones the actor may actually View,
     * delegating to the AccessResolver (the EXACT role-union / inheritance-boundary
     * gate). This is the correctness companion to the SUPERSET candidate query: a
     * caller lists candidates cheaply, then runs the page through here.
     *
     * @param  iterable<Node> $nodes
     * @return list<Node>
     */
    public function filterViewable(Authorizable $actor, iterable $nodes): array
    {
        return $this->access->filterSharedWith($actor, $nodes);
    }

    /**
     * The "shared with me" top-level view: the nodes the actor holds a live
     * grant on, RE-RESOLVED through the full algorithm (the EXACT gate, over a bounded candidate set —
     * unlike the candidate-SQL listings). Cross-storage by nature: the subject index
     * is not storage-bound.
     *
     * Eager-loads the storage relation so the per-candidate re-resolution does NOT
     * fire one storage query per node: the {@see AccessResolver} prefers an
     * already-loaded `storage` relation over a per-node lookup, so this `with('storage')`
     * spares the gate's per-node storage query (and the NodeResource reads the same
     * relation). Without it the eager-load would be dead weight.
     *
     * @return Collection<int, Node>
     */
    public function sharedWith(Authorizable $actor): Collection
    {
        $candidateIds = $this->access->sharedNodeIds($actor);

        if ($candidateIds === []) {
            /** @var Collection<int, Node> $empty */
            $empty = new Collection;

            return $empty;
        }

        $base = $this->query()
            ->whereNull('trashed_at')
            ->with('storage');

        /** @var Collection<int, Node> $candidates */
        $candidates = $this->whereKeyInBounded($base, $candidateIds)->get();

        /** @var Collection<int, Node> $visible */
        $visible = new Collection($this->filterViewable($actor, $candidates));

        return $visible;
    }

    /**
     * Whether any profile needs a grant candidate predicate. Mixed installations
     * must keep that predicate: an open host lane only opens its own drives.
     * Candidate ids are an optimization; Node listings still exact-filter access.
     */
    public function grantFilteringApplies(): bool
    {
        foreach ($this->profiles()->all() as $profile) {
            $driver = $this->drivers->forProfile($profile->getKey());
            if ($this->vaulterConfig->featureEnabled('grants', $profile->getKey())
                && $driver instanceof GrantAuthoritativeDriver
                && $driver->isGrantAuthoritative()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The actor's candidate accessible node ids: the grant anchors UNION the
     * descendants of every SUBTREE-scoped anchor. Item-scoped anchors contribute
     * only their own node. A SUPERSET — see {@see queryForUser()}.
     *
     * PUBLIC: {@see constrainToAccessible()} narrows a query by the
     * NODE model's own primary key, which only fits a Node-shaped listing. The
     * admin panel's non-Node resources (grant/share/activity/storage) key off a
     * DIFFERENT column (node_id / target_id / root_node_id), so they call this
     * directly to build their own `whereIn` — same candidate set, same
     * grantFilteringApplies() gate, no duplicated grant-resolution logic.
     *
     * @return list<int|string>
     */
    public function candidateNodeIds(Authorizable $actor): array
    {
        $driveModel = $this->vaulterConfig->model('drive');
        $anchors = [];
        $openIds = [];
        foreach (app(TenancyBoundary::class)->constrain($driveModel::query())->cursor() as $drive) {
            $driver = $this->drivers->forProfile($drive->profile);
            if (!$this->vaulterConfig->featureEnabled('grants', $drive->profile)
                || !$driver instanceof GrantAuthoritativeDriver
                || !$driver->isGrantAuthoritative()) {
                foreach ($this->query()->where('drive_id', $drive->id)->pluck('id') as $id) {
                    if (!is_int($id) && !is_string($id)) {
                        throw new \LogicException('Invalid candidate node identity.');
                    }
                    $openIds[] = $id;
                }

                continue;
            }
            $subjects = $this->drivers->subjectsFor($driver, $actor, AccessScope::forDrive($drive));
            $rows = $this->grants->allowAnchorsFor(subjects: $subjects, now: Carbon::now());
            $nodeIds = [];
            foreach (array_chunk($rows->pluck('node_id')->all(), 500) as $chunk) {
                array_push($nodeIds, ...$this->query()->where('drive_id', $drive->id)->whereKey($chunk)->pluck('id')->all());
            }
            $keys = array_fill_keys(array_map(Identity::stringify(...), $nodeIds), true);
            foreach ($rows as $row) {
                if (isset($keys[Identity::stringify($row->node_id)])) {
                    $anchors[] = $row;
                }
            }
        }

        if ($anchors === []) {
            return $openIds;
        }

        /** @var array<string, int|string> $ids */
        $ids = [];
        foreach ($openIds as $id) {
            $ids[Identity::stringify($id)] = $id;
        }
        /** @var array<string, int|string> $subtreeAnchorIds */
        $subtreeAnchorIds = [];

        foreach ($anchors as $anchor) {
            $ids[Identity::stringify($anchor->node_id)] = $anchor->node_id;

            if ($anchor->scope === GrantScope::Subtree) {
                // Dedup by stringified key: the same subtree anchor can appear under
                // several subjects, but it seeds the descent exactly once.
                $subtreeAnchorIds[Identity::stringify($anchor->node_id)] = $anchor->node_id;
            }
        }

        foreach ($this->descendantIdsOf(array_values($subtreeAnchorIds)) as $descendantId) {
            $ids[Identity::stringify($descendantId)] = $descendantId;
        }

        return array_values($ids);
    }

    /**
     * The descendant node ids BELOW the given subtree anchors, via the staudenmeir
     * recursive CTE, bounded by withMaxDepth, never whereHas('descendants') /
     * withCount('descendants'). KEY-ONLY: the only full models hydrated are the anchors
     * themselves (bounded by the actor's grant count, NOT the subtree size); each
     * anchor's descendant subtree is pulled with ->pluck(key) — node KEYS, never
     * hydrated Node models — so a wide subtree never inflates memory.
     *
     * One recursive descent per anchor: the Node model REPURPOSES the staudenmeir
     * treeOf scope as a storage filter (scopeTreeOf(storageId)), so the multi-root
     * single-seed descent is not available here; the per-anchor loop stays, but the
     * anchor set is already deduped by the caller and only keys are read.
     *
     * @param  list<int|string> $anchorIds
     * @return list<int|string>
     */
    private function descendantIdsOf(array $anchorIds): array
    {
        if ($anchorIds === []) {
            return [];
        }

        $model = $this->vaulterConfig->model('node');
        $keyName = (new $model)->getKeyName();

        /** @var Collection<int, Node> $anchors */
        $anchors = $this->whereKeyInBounded(app(TenancyBoundary::class)->constrain($model::query()), $anchorIds)->get();

        /** @var list<int|string> $ids */
        $ids = [];

        foreach ($anchors as $anchor) {
            $this->metrics->histogram(
                name: 'vaulter_tree_cte_anchors',
                value: 1,
                labels: ['tier' => LoadTier::label()],
            );

            /** @var list<int|string> $descendantKeys */
            $descendantKeys = $model::withMaxDepth(
                $this->treeBound(),
                static fn (): array => $anchor->descendants()->pluck($keyName)->all(),
            );

            foreach ($descendantKeys as $descendantKey) {
                $ids[] = $descendantKey;
            }
        }

        return $ids;
    }

    /**
     * Bound the ID list's contribution to the statement-wide parameter count.
     * OR-ing whereIn chunks still binds every ID in one statement. Above the small
     * set budget, Laravel's connection escape() safely quotes each value through
     * the read PDO driver. No integer casts: ULIDs and bigint strings retain their
     * exact values. The predicate remains one AND-ed IN condition, so existing
     * storage/scopes, ordering and SQL cursor pagination keep their semantics.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int|string> $ids
     * @return Builder<TModel>
     */
    private function whereKeyInBounded(Builder $query, array $ids): Builder
    {
        $keyName = $query->getModel()->getKeyName();

        if (count($ids) <= self::WHERE_IN_BIND_LIMIT) {
            return $query->whereIn($keyName, $ids);
        }

        return $query->whereRaw(new EscapedKeySet($keyName, $ids));
    }

    private function scopedNode(Node $node): Node
    {
        if (!app(TenancyConfig::class)->enabled()) {
            return $node;
        }
        app(TenancyBoundary::class)->assertNode($node);

        return $node->newQuery()->whereKey($node->getRawOriginal($node->getKeyName()))->firstOrFail();
    }

    /** @param Collection<int, Node> $nodes
     * @return Collection<int, Node>
     */
    private function checkedTree(Collection $nodes, Node $root): Collection
    {
        if (app(TenancyConfig::class)->enabled()) {
            $boundary = app(TenancyBoundary::class);
            $boundary->assertTree($nodes, $boundary->assertNode($root));
        }

        return $nodes;
    }

    /** @return Builder<Node> */
    private function query(): Builder
    {
        $model = $this->vaulterConfig->model('node');

        return app(TenancyBoundary::class)->constrain($model::query());
    }
}
