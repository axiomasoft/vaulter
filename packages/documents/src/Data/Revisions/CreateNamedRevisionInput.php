<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Models\Drive\Node;

/**
 * Command for CreateNamedRevisionAction (§2.5 / M4a): checkpoint a document's
 * current working state as a NAMED (non-autosave) revision with a label. keepForever
 * pins the snapshot out of the prune window.
 */
final readonly class CreateNamedRevisionInput
{
    public function __construct(
        public Node $node,
        public ?string $label = null,
        public bool $keepForever = false,
    ) {}
}
