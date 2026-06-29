<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Node;

/**
 * A node (folder, file or document) was created in a storage tree (§2.2).
 */
final readonly class NodeCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public int|string|null $actorId = null,
    ) {}
}
