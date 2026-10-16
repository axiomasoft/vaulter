<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Node;

/**
 * Command for CreateNamedRevisionAction: checkpoint a document's
 * current working state as a NAMED (non-autosave) revision with a label. keepForever
 * pins the snapshot out of the prune window.
 */
#[Api]
final readonly class CreateNamedRevisionInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public ?string $label = null,
        public bool $keepForever = false,
    ) {}
}
