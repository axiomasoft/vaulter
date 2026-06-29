<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Node;

/**
 * A node was copied to a destination (§2.4). For files the copy references the
 * SAME blob through a new edge (dedup keeps one physical object); for folders the
 * whole subtree is deep-copied. $copy is the newly created destination root.
 */
final readonly class NodeCopied implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $source,
        public Node $copy,
        public int|string|null $actorId = null,
    ) {}
}
