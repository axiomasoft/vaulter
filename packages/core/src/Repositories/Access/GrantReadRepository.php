<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Read-side queries over v_node_grants (§2.6). It fetches the live, non-expired
 * grant rows for a subject-set across a set of nodes — the raw material the
 * AccessResolver folds into a GrantSet. Expiry is enforced HERE, read-time (H.2):
 * an expired row never reaches the resolver.
 */
final class GrantReadRepository
{
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

        $model = Config::grantModel();

        /** @var Collection<int, NodeGrant> $grants */
        $grants = $model::query()
            ->whereIn('node_id', $nodeIds)
            ->forSubjects($subjects)
            ->notExpired($now)
            ->get();

        return $grants;
    }

    /**
     * Every live grant directly on a node (the permissions UI listing, §2.6).
     * Returns a Builder so the controller paginates it. `orderByDesc('id')` is a
     * stable tie-breaker (F-P4.2-05, VLT-REMED P4.3): grants created in the same
     * transaction/batch share an identical created_at timestamp, so
     * cursorPaginate()'s keyset would otherwise skip/duplicate rows on that page
     * boundary — the same pattern already applied in
     * {@see Activity::scopeNewestFirst()}.
     *
     * @return Builder<NodeGrant>
     */
    public function forNode(Node $node): Builder
    {
        $model = Config::grantModel();

        return $model::query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Every live, non-expired ALLOW grant addressed to the subject-set across ALL
     * nodes (the §2.6 subject index, unbounded by an ancestor chain). The
     * access-aware listing seam (NodeReadRepository::queryForUser) reads these as
     * the candidate ANCHORS: an item-scoped anchor contributes only its own node,
     * a subtree-scoped anchor additionally contributes its descendants. The caller
     * still re-resolves each candidate through the AccessResolver, because a nearer
     * deny may revoke a row (H.2) — this never decides visibility on its own.
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

        $model = Config::grantModel();

        /** @var Collection<int, NodeGrant> $grants */
        $grants = $model::query()
            ->forSubjects($subjects)
            ->notExpired($now)
            ->where('effect', 'allow')
            ->get();

        return $grants;
    }

    /**
     * The distinct node ids a subject-set has ANY grant on — the "shared with me"
     * fast pass (§2.6 subject index). The caller re-resolves each node through the
     * AccessResolver, because a nearer deny may have revoked a row (H.2).
     *
     * @param  list<Subject>    $subjects
     * @return list<int|string>
     */
    public function nodeIdsSharedWith(array $subjects, Carbon $now): array
    {
        if ($subjects === []) {
            return [];
        }

        $model = Config::grantModel();

        /** @var list<int|string> $ids */
        $ids = $model::query()
            ->forSubjects($subjects)
            ->notExpired($now)
            ->where('effect', 'allow')
            ->distinct()
            ->pluck('node_id')
            ->all();

        return $ids;
    }
}
