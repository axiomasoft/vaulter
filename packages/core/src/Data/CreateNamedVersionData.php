<?php

declare(strict_types=1);

namespace Vaulter\Data;

use Vaulter\Models\Node;

/**
 * Command for CreateNamedVersionAction (§2.5 / M4a): snapshot a document node's
 * current head content as a NAMED (non-autosave) version with a label. keepForever
 * pins the snapshot out of the prune window.
 */
final readonly class CreateNamedVersionData
{
    public function __construct(
        public Node $node,
        public string $label,
        public bool $keepForever = false,
        public int|string|null $actorId = null,
    ) {}
}
