<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Drive\Node;

/**
 * Command for CopyNodeAction (§2.4): copy $node into $into (null = storage root).
 * A folder is deep-copied; a file gets a new File+FileVersion referencing the
 * SAME blob through a new edge (dedup keeps one physical object). $name overrides
 * the copy's name at the destination.
 */
final readonly class CopyNodeData
{
    public function __construct(
        public Node $node,
        public ?Node $into = null,
        public ?string $name = null,
        public int|string|null $actorId = null,
    ) {}
}
