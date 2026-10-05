<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Models\Drive\Node;

/**
 * Command for CreateNamedRevisionAction (§2.5 / M4a): snapshot a document node's
 * current head content as a NAMED (non-autosave) version with a label. keepForever
 * pins the snapshot out of the prune window.
 */
final readonly class CreateNamedRevisionInput
{
    public function __construct(
        public Node $node,
        public string $label,
        public bool $keepForever = false,
        public int|string|null $actorId = null,
    ) {}
}
