<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * Shared candidate-node resolution for the four Filament resources that key off a
 * node (grant/share/activity via node_id or target_id, storage via root_node_id) —
 * F-P1.10-01. Mirrors {@see NodeReadRepository::constrainToAccessible()}'s
 * semantics exactly (same actor resolution, same grantFilteringApplies() gate, same
 * candidate-set superset) but returns the raw id list instead of composing on a
 * Node-keyed query, since these resources' own primary key is not a node id.
 *
 * Technical axis only — resources decide WHICH column the ids narrow.
 */
final class PanelAccessScope
{
    private function __construct() {}

    /**
     * The actor's candidate node ids for the current panel request, or null when
     * the active driver does not narrow listings at all (grantFilteringApplies()
     * false — the host's roles govern, listing stays OPEN, same as NodeResource).
     * An empty list means "narrow to nothing" (no acting user, or an acting user
     * with zero grant anchors) — the caller MUST turn that into a deny-all
     * predicate, never treat it as "no filter".
     *
     * @return list<int|string>|null
     */
    public static function candidateNodeIds(): ?array
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return [];
        }

        $nodes = app(NodeReadRepository::class);

        if (!$nodes->grantFilteringApplies()) {
            return null;
        }

        return $nodes->candidateNodeIds($actor);
    }
}
