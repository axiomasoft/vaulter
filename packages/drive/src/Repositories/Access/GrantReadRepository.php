<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side queries over v_node_grants. It fetches the live, non-expired
 * grant rows for a subject-set across a set of nodes — the raw material the
 * AccessResolver folds into a GrantSet. Expiry is enforced HERE, read-time:
 * an expired row never reaches the resolver.
 */
#[Internal]
final class GrantReadRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly TenancyBoundary $boundary,
    ) {}

    /**
     * Every live, non-expired grant addressed to any subject in $subjects on any
     * node in $nodeIds. One query backs the whole ancestor walk: the resolver
     * passes the bounded ancestor-chain ids and matches by relative position.
     *
     * @param  list<int|string>           $nodeIds
     * @param  list<Subject>              $subjects
     * @return Collection<int, NodeGrant>
     */
    public function forSubjectsOnNodes(array $nodeIds, array $subjects, Carbon $now): Collection
    {
        if ($nodeIds === [] || $subjects === []) {
            /** @var Collection<int, NodeGrant> $empty */
            $empty = new Collection;

            return $empty;
        }

        $model = $this->vaulterConfig->model('grant');

        /** @var Collection<int, NodeGrant> $grants */
        $grants = $this->boundary->constrain($model::query(), 'node.storage')
            ->whereIn('node_id', $nodeIds)
            ->forSubjects($subjects)
            ->notExpired($now)
            ->get();

        return $grants;
    }

    /**
     * Every live grant directly on a node (the permissions UI listing).
     * Returns a Builder so the controller paginates it. `orderByDesc('id')` is a
     * stable tie-breaker: grants created in the same
     * transaction/batch share an identical created_at timestamp, so
     * cursorPaginate()'s keyset would otherwise skip/duplicate rows on that page
     * boundary — the same pattern already applied in
     * {@see Activity::scopeNewestFirst()}.
     *
     * @return Builder<NodeGrant>
     */
    public function forNode(Node $node): Builder
    {
        $model = $this->vaulterConfig->model('grant');

        return $this->boundary->constrain($model::query(), 'node.storage')
            ->where('node_id', $node->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Every live, non-expired grant addressed to the subject-set across ALL
     * nodes (the subject index, unbounded by an ancestor chain). The
     * access-aware listing seam (NodeReadRepository::queryForUser) reads these as
     * the candidate ANCHORS: an item-scoped anchor contributes only its own node,
     * a subtree-scoped anchor additionally contributes its descendants. The caller
     * still re-resolves each candidate through the AccessResolver, because an
     * inheritance boundary may cut a subtree anchor off — this never decides
     * visibility on its own.
     *
     * @param  list<Subject>              $subjects
     * @return Collection<int, NodeGrant>
     */
    public function allowAnchorsFor(array $subjects, Carbon $now): Collection
    {
        if ($subjects === []) {
            /** @var Collection<int, NodeGrant> $empty */
            $empty = new Collection;

            return $empty;
        }

        $model = $this->vaulterConfig->model('grant');

        /** @var Collection<int, NodeGrant> $grants */
        $grants = $this->boundary->constrain($model::query(), 'node.storage')
            ->forSubjects($subjects)
            ->notExpired($now)
            ->get();

        return $grants;
    }

    /**
     * The distinct node ids a subject-set has ANY grant on — the "shared with me"
     * fast pass (subject index). The caller re-resolves each node through the
     * AccessResolver (an inheritance boundary or a host override may still hide it).
     *
     * @param  list<Subject>    $subjects
     * @return list<int|string>
     */
    public function nodeIdsSharedWith(array $subjects, Carbon $now): array
    {
        if ($subjects === []) {
            return [];
        }

        $model = $this->vaulterConfig->model('grant');

        /** @var list<int|string> $ids */
        $ids = $this->boundary->constrain($model::query(), 'node.storage')
            ->forSubjects($subjects)
            ->notExpired($now)
            ->distinct()
            ->pluck('node_id')
            ->all();

        return $ids;
    }

    /**
     * The distinct node ids of one drive a subject-set holds a live grant on: the only
     * places below an invisible root where that subject-set can see anything.
     *
     * @param  list<Subject>    $subjects
     * @return list<int|string>
     */
    public function nodeIdsGrantedIn(array $subjects, int|string $driveId, Carbon $now): array
    {
        if ($subjects === []) {
            return [];
        }

        $model = $this->vaulterConfig->model('grant');

        /** @var list<int|string> $ids */
        $ids = $this->boundary->constrain($model::query(), 'node.storage')
            ->whereHas('node', static fn (Builder $query): Builder => $query->where('drive_id', $driveId))
            ->forSubjects($subjects)
            ->notExpired($now)
            ->distinct()
            ->pluck('node_id')
            ->all();

        return $ids;
    }

    /**
     * Every live subtree grant on the given nodes, for any subject: what a node below
     * them currently inherits when those nodes are its ancestors up to the boundary.
     *
     * @param  list<int|string>           $nodeIds
     * @return Collection<int, NodeGrant>
     */
    public function subtreeGrantsOn(array $nodeIds, Carbon $now): Collection
    {
        if ($nodeIds === []) {
            /** @var Collection<int, NodeGrant> $empty */
            $empty = new Collection;

            return $empty;
        }
        $model = $this->vaulterConfig->model('grant');
        /** @var Collection<int, NodeGrant> $grants */
        $grants = $this->boundary->constrain($model::query(), 'node.storage')
            ->whereIn('node_id', $nodeIds)
            ->where('scope', GrantScope::Subtree->value)
            ->notExpired($now)
            ->orderBy('id')
            ->get();

        return $grants;
    }
}
