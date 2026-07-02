<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use Vaulter\Models\Drive\Node;

/**
 * Command for RenameNodeAction (§2.2): rename $node in place, subject to the
 * sibling-name conflict check at its current parent.
 */
final readonly class RenameNodeData
{
    public function __construct(
        public Node $node,
        public string $name,
        public int|string|null $actorId = null,
    ) {}
}
