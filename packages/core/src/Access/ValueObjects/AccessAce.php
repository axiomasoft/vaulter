<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;

/**
 * One access-control entry in an {@see AccessExplanation} provenance chain
 * (F-P1.1-02): a single v_node_grants row that the bounded ancestor walk visited,
 * annotated with the walk DEPTH it was seen at (0 = the node itself, larger =
 * farther ancestor) and whether it actually COUNTED toward the fold.
 *
 * A row does NOT count when it is an ancestor grant whose scope does not inherit
 * downward (an item-scoped grant on a parent) — it is recorded with $counted=false
 * so an operator debugging effective access can see it was present but inert.
 *
 * Purely diagnostic — built ONLY by AccessResolver::explain(), never on the hot
 * can()/effectiveGrants() path.
 */
final readonly class AccessAce
{
    public function __construct(
        public int|string $nodeId,
        public int $depth,
        public Effect $effect,
        public GrantScope $scope,
        public int $bits,
        public bool $counted,
    ) {}
}
