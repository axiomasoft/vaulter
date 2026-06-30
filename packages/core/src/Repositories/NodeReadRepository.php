<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Models\Node;
use Vaulter\Models\Storage;
use Vaulter\Support\Config;

/**
 * Read-side queries over the v_nodes adjacency tree (§2.2 / H.4). Tree reads use
 * the staudenmeir recursive relationships (descendants/ancestors), always bounded
 * by treeOf(storage) and — for bulk descents — withMaxDepth, never the BANNED
 * whereHas('descendants') / withCount('descendants') which break on MariaDB and
 * degrade on Postgres (H.4 arch-ban).
 *
 * M2 will add an access-aware queryForUser() seam (HostRoleDriver / AccessResolver)
 * that every public read method routes through. This slice runs open — the
 * methods return the unfiltered tree.
 */
final class NodeReadRepository
{
    /**
     * The maximum recursion depth for bulk subtree descents — file managers live
     * at depth ≤ ~20 (§1.5), so this caps a pathological tree without an explicit
     * caller bound.
     */
    public const int DEFAULT_MAX_DEPTH = 64;

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
     * @return Builder<Node>
     */
    private function query(): Builder
    {
        $model = Config::nodeModel();

        return $model::query();
    }
}
