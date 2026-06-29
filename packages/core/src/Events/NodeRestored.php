<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Node;

/**
 * A trashed node and its subtree were restored (§2.10): the trashed triplet is
 * cleared. The node may have been re-homed to root when its original parent was
 * gone.
 */
final readonly class NodeRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public int|string|null $actorId = null,
    ) {}
}
