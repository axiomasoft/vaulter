<?php

declare(strict_types=1);

namespace Vaulter\Events\Drive;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Drive\Node;

/**
 * A node was re-parented within its storage tree — a single parent_id UPDATE
 * (§2.2 / H.4). Carries the old parent so listeners can reconstruct the move.
 */
final readonly class NodeMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public int|string|null $fromParentId,
        public int|string|null $toParentId,
        public int|string|null $actorId = null,
    ) {}
}
