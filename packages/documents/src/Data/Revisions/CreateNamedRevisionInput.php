<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Node;

/**
 * Command for CreateNamedRevisionAction: checkpoint a document's
 * current working state as a NAMED (non-autosave) revision with a label. keepForever
 * pins the snapshot out of the prune window.
 */
#[Internal]
final readonly class CreateNamedRevisionInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public ?string $label = null,
        public bool $keepForever = false,
    ) {}
}
