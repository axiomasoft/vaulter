<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;

/**
 * Command for MoveNodeAction (§2.2 / H.4): re-parent $node under $into (null =
 * storage root). A move never crosses storages; the cycle-guard and sibling-name
 * conflict are enforced by the Action.
 */
final readonly class MoveNodeData
{
    public function __construct(
        public Node $node,
        public ?Node $into = null,
        public int|string|null $actorId = null,
    ) {}
}
