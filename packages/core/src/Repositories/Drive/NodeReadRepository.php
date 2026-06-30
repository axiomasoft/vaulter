<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Contracts\Access\GrantAuthoritativeDriver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Access\GrantReadRepository;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Read-side queries over the v_nodes adjacency tree (§2.2 / H.4). Tree reads use
 * the staudenmeir recursive relationships (descendants/ancestors), always bounded
 * by treeOf(storage) and — for bulk descents — withMaxDepth, never the BANNED
 * whereHas('descendants') / withCount('descendants') which break on MariaDB and
 * degrade on Postgres (H.4 arch-ban).
 *
 * The access-aware seam (P1 #3) — queryForUser() (the standalone live-nodes entry)
 * and constrainToAccessible() (compose onto any base) — narrows a query to the actor's
 * grant-reachable CANDIDATE node ids when grant-filtering applies (the 'grants' feature
 * is on AND the active permission driver carries the {@see GrantAuthoritativeDriver}
 * marker — only the standalone {@see DatabasePermissionResolver} does). For every other
 * driver the host's roles govern and v_node_grants is not the source of truth, so
 * listing stays OPEN. The candidate set is a deliberate SUPERSET: it hides nodes with
 * no grant-reachability but does NOT apply the exact deny-veto / inherit-cutoff in the
 * listing SQL (that exact listing suppression is DEFERRED to the H.4 read-model). The
 * EXACT gate is the {@see AccessResolver}, applied per ACTION (show/mutations) and per
 * candidate by filterViewable() / sharedWith() (the bounded "shared with me" set).
 */
final class NodeReadRepository
{
    /**
     * The maximum recursion depth for bulk subtree descents — file managers live
     * at depth ≤ ~20 (§1.5), so this caps a pathological tree without an explicit
     * caller bound.
     */
    public const int DEFAULT_MAX_DEPTH = 64;

    /**
     * The per-group cap when chunking a candidate-id list into OR-ed whereIn groups.
     * SQLite caps bound variables at ~999 (SQLITE_MAX_VARIABLE_NUMBER) and Postgres
     * at 65535; 900 stays clear of the SQLite wall (tests run SQLite — a wide subtree
     * would otherwise HARD-ERROR) with margin for the rest of the query's bindings.
     */
    private const int WHERE_IN_CHUNK = 900;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly HostRoleDriverManager $drivers,
        private readonly GrantReadRepository $grants,
    ) {}

    public function find(int|string $id): ?Node
    {
        return $this->query()->find($id);
    }

    /**
     * Every live (non-trashed, non-deleted) node in a storage's tree.
     *
     * @return Builder<Node>
     */
    public function forStorage(Storage $storage): Builder
    {
        return $this->query()
            ->treeOf($storage->id)
            ->whereNull('trashed_at');
    }

    /**
     * Direct children of a folder (or the storage root when $parent is null),
     * ordered by the manual sibling position then name.
     *
     * @return Builder<Node>
     */
    public function children(Storage $storage, ?Node $parent): Builder
    {
        return $this->query()
            ->treeOf($storage->id)
            ->whereNull('trashed_at')
            ->where('parent_id', $parent?->id)
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * The whole subtree below a node via the recursive CTE, bounded by
     * withMaxDepth so the descent never runs unbounded (H.4). The node itself is
     * NOT part of descendants() — callers add it when needed.
     *
     * @return Collection<int, Node>
     */
    public function descendants(Node $node, ?int $maxDepth = null): Collection
    {
        $model = Config::nodeModel();

        /** @var Collection<int, Node> $result */
        $result = $model::withMaxDepth(
            $maxDepth ?? self::DEFAULT_MAX_DEPTH,
            static fn (): Collection => $node->descendants()->get(),
        );

        return $result;
    }

    /**
     * The node plus its whole subtree (the node first), bounded by withMaxDepth.
     * This is the unit a trash/restore/force-delete touches atomically.
     *
     * @return Collection<int, Node>
     */
    public function subtree(Node $node, ?int $maxDepth = null): Collection
    {
        $model = Config::nodeModel();

        /** @var Collection<int, Node> $result */
        $result = $model::withMaxDepth(
            $maxDepth ?? self::DEFAULT_MAX_DEPTH,
            static fn (): Collection => $node->descendantsAndSelf()->get(),
        );

        return $result;
    }

    /**
     * The ancestor chain from the node up to the storage root (nearest first).
     *
     * @return Collection<int, Node>
     */
    public function ancestors(Node $node): Collection
    {
        return $node->ancestors()->get();
    }

    /**
     * Breadcrumb from the storage root down to the node (root first), the node
     * included as the last crumb.
     *
     * @return Collection<int, Node>
     */
    public function breadcrumb(Node $node): Collection
    {
        /** @var Collection<int, Node> $crumbs */
        $crumbs = $node->ancestorsAndSelf()->get();

        /** @var Collection<int, Node> $ordered */
        $ordered = $crumbs->reverse()->values();

        return $ordered;
    }

    /**
     * Name search within a storage tree (live nodes only). Matches a case-folded
     * substring of the node name — the §2.2 search index backs this.
     *
     * @return Builder<Node>
     */
    public function search(Storage $storage, string $term): Builder
    {
        return $this->query()
            ->treeOf($storage->id)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
            ->orderBy('name');
    }

    /**
     * The trash view: trashed (but not yet permanently deleted) nodes in a
     * storage, newest first.
     *
     * @return Builder<Node>
     */
    public function trashed(Storage $storage): Builder
    {
        return $this->query()
            ->treeOf($storage->id)
            ->whereNotNull('trashed_at')
            ->orderByDesc('trashed_at');
    }

    /**
     * The trashed nodes whose trashed_at is at or before $threshold — the
     * purge-trash candidates past their retention window (§2.10). Only ROOTS of a
     * trash operation are returned: a node whose own parent is itself trashed is
     * skipped, because force-deleting an ancestor's subtree already reclaims it (so
     * a child is never purged twice). Returns a Builder so the command chunks it.
     *
     * @return Builder<Node>
     */
    public function trashedBefore(Carbon $threshold): Builder
    {
        $model = Config::nodeModel();
        $table = (new $model)->getTable();

        return $this->query()
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', $threshold)
            // Skip descendants whose own parent is also trashed — the trashed
            // ancestor is the purge root and its subtree force-delete covers them.
            ->whereNotExists(static function (QueryBuilder $sub) use ($table): void {
                $sub->selectRaw('1')
                    ->from($table.' as parent_node')
                    ->whereColumn('parent_node.id', $table.'.parent_id')
                    ->whereNotNull('parent_node.trashed_at');
            })
            ->orderBy('trashed_at');
    }

    /**
     * Whether a live sibling already owns $name under $parentId in this storage
     * (case-insensitive) — the §2.2 sibling-unique predicate the Actions check
     * before create/move/rename. $ignoreId excludes the node being renamed in
     * place. Uses a direct EXISTS, NOT a recursive descendant aggregate (H.4).
     */
    public function siblingNameExists(
        Storage $storage,
        int|string|null $parentId,
        string $name,
        int|string|null $ignoreId = null,
    ): bool {
        $query = $this->query()
            ->treeOf($storage->id)
            ->where('parent_id', $parentId)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    /**
     * The STANDALONE public seam (master plan P1 #3): the live (non-trashed) nodes an
     * actor may reach, as a Builder for pagination / Filament. It is the named entry
     * for "give me this actor's nodes" = the live-nodes base + constrainToAccessible.
     * A caller that needs its own trashed-state control (the children cursor below, or
     * the Filament table whose Trashed filter flips trashed_at) composes
     * {@see constrainToAccessible()} on its own base instead.
     *
     * When grant-filtering applies (see {@see grantFilteringApplies()}) the base is
     * constrained to the actor's CANDIDATE accessible node ids; otherwise the base is
     * returned UNCHANGED (open listing — the host's roles govern).
     *
     * The candidate set is the grant ANCHORS for the actor's subject-set UNION the
     * descendants of every SUBTREE-scoped anchor (an item-scoped anchor contributes
     * only its own node). It is a deliberate SUPERSET: it does NOT apply the deny-veto
     * or the inherit_grants cut-off in SQL, so a node a nearer deny revokes can still
     * be in the set. The candidate-set listing semantic is documented on
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
     * set (P1 #3), WITHOUT imposing the live/trashed filter — so a caller that owns
     * its own trashed-state handling (the children cursor; the Filament table, whose
     * Trashed filter flips trashed_at) composes the access narrowing on top of its own
     * query. A no-op when grant-filtering does not apply (open drivers); forces an
     * empty result when it applies and the actor reaches no candidate.
     *
     * LISTING SEMANTIC (deliberate, master plan P1 #3 + H.4): a constrained listing
     * narrows to the actor's grant-reachable CANDIDATE set — it HIDES nodes with no
     * grant-reachability (the common "inaccessible" / "not shared with me" case) but
     * is a SUPERSET that does NOT apply the exact deny-veto or the inherit_grants
     * cut-off in SQL. A node a NEARER deny revokes (or one past an inherit-cutoff wall)
     * can therefore still appear in a listing. Pushing that exact suppression into the
     * listing SQL is explicitly DEFERRED to the H.4 materialised effective-grants
     * read-model (built "when profiling requires"). This keeps the API listing and the
     * Filament table CONSISTENT (both candidate-SQL). Per-ACTION authorization is
     * UNCHANGED and stays EXACT: the {@see AccessResolver} gates every mutation/show,
     * and the cross-storage "shared with me" view re-resolves each candidate exactly
     * via {@see sharedWith()}.
     *
     * @param  Builder<Node> $query
     * @return Builder<Node>
     */
    public function constrainToAccessible(Builder $query, Authorizable $actor): Builder
    {
        if (!$this->grantFilteringApplies()) {
            return $query;
        }

        $candidateIds = $this->candidateNodeIds($actor);

        if ($candidateIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->whereKeyInChunked($query, $candidateIds);
    }

    /**
     * Narrow an already-loaded set of nodes to the ones the actor may actually View,
     * delegating to the AccessResolver (the EXACT deny-veto / inherit-cutoff gate,
     * H.2). This is the correctness companion to the SUPERSET candidate query: a
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
     * The "shared with me" top-level view (§2.6 / H.2): the nodes the actor holds a
     * live allow-grant on, RE-RESOLVED through the full algorithm so a nearer deny
     * that revokes a row excludes it (the EXACT gate, over a bounded candidate set —
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
        $candidates = $this->whereKeyInChunked($base, $candidateIds)->get();

        /** @var Collection<int, Node> $visible */
        $visible = new Collection($this->filterViewable($actor, $candidates));

        return $visible;
    }

    /**
     * Whether the access-aware grant narrowing applies to a listing. It does ONLY
     * when the 'grants' feature is on AND the active permission driver is
     * {@see GrantAuthoritativeDriver} with {@see GrantAuthoritativeDriver::isGrantAuthoritative()}
     * true — i.e. treats v_node_grants as the SOLE authority for read-visibility (the
     * standalone {@see DatabasePermissionResolver}).
     *
     * The gate keys on the CAPABILITY, NOT on the driver KEY string: a host that
     * SUBCLASSES DatabasePermissionResolver under the same 'database' key to add a
     * role/team lane or a super-admin override() confers access on nodes with NO grant
     * anchor — so it overrides isGrantAuthoritative() to return false (the interface is
     * inherited and cannot be un-declared), and the listing stays OPEN. For the
     * gate/spatie/azgard/null drivers the host's roles govern and v_node_grants is not
     * the source of truth, so a grant-id constraint would WRONGLY hide nodes the host
     * role lane allows; they don't implement the capability either ⇒ listing stays OPEN
     * (the safe default that never wrongly hides).
     *
     * Public so a caller listing a page through a NON-queryForUser path (the children
     * cursor, the Filament table) can decide whether to narrow the query to the
     * candidate set up front.
     */
    public function grantFilteringApplies(): bool
    {
        $driver = $this->drivers->driver();

        return Config::featureEnabled('grants')
            && $driver instanceof GrantAuthoritativeDriver
            && $driver->isGrantAuthoritative();
    }

    /**
     * The actor's candidate accessible node ids: the grant anchors UNION the
     * descendants of every SUBTREE-scoped anchor. Item-scoped anchors contribute
     * only their own node. A SUPERSET — see {@see queryForUser()}.
     *
     * @return list<int|string>
     */
    private function candidateNodeIds(Authorizable $actor): array
    {
        $subjects = $this->drivers->driver()->subjectsFor($actor);
        $anchors = $this->grants->allowAnchorsFor(subjects: $subjects, now: Carbon::now());

        if ($anchors->isEmpty()) {
            return [];
        }

        /** @var array<string, int|string> $ids */
        $ids = [];
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
     * recursive CTE, bounded by withMaxDepth — H.4, never whereHas('descendants') /
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

        $model = Config::nodeModel();
        $keyName = (new $model)->getKeyName();

        /** @var Collection<int, Node> $anchors */
        $anchors = $this->whereKeyInChunked($model::query(), $anchorIds)->get();

        /** @var list<int|string> $ids */
        $ids = [];

        foreach ($anchors as $anchor) {
            /** @var list<int|string> $descendantKeys */
            $descendantKeys = $model::withMaxDepth(
                self::DEFAULT_MAX_DEPTH,
                static fn (): array => $anchor->descendants()->pluck($keyName)->all(),
            );

            foreach ($descendantKeys as $descendantKey) {
                $ids[] = $descendantKey;
            }
        }

        return $ids;
    }

    /**
     * Apply a primary-key whereIn over an arbitrarily large id list WITHOUT exceeding
     * the driver's bound-variable cap (SQLite ~999, Postgres 65535). The ids are split
     * into {@see WHERE_IN_CHUNK}-sized groups OR-ed inside a single where() clause, so
     * the narrowing never HARD-ERRORS on a wide candidate set. A list at or under the
     * cap compiles to a single whereIn (no nesting overhead).
     *
     * @param  Builder<Node>    $query
     * @param  list<int|string> $ids
     * @return Builder<Node>
     */
    private function whereKeyInChunked(Builder $query, array $ids): Builder
    {
        $keyName = $query->getModel()->getKeyName();

        if (count($ids) <= self::WHERE_IN_CHUNK) {
            return $query->whereIn($keyName, $ids);
        }

        $chunks = array_chunk($ids, self::WHERE_IN_CHUNK);

        return $query->where(static function (Builder $inner) use ($chunks, $keyName): void {
            foreach ($chunks as $chunk) {
                $inner->orWhereIn($keyName, $chunk);
            }
        });
    }

    /**
     * @return Builder<Node>
     */
    private function query(): Builder
    {
        $model = Config::nodeModel();

        return $model::query();
    }
}
