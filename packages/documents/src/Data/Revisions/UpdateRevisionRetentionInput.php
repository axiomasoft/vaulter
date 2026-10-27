<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/**
 * Command for UpdateRevisionRetentionAction: pin a checkpoint of the node's document
 * out of the prune window (keepForever) or release the pin. The revision is resolved
 * inside the node's own aggregate.
 */
#[Internal]
final readonly class UpdateRevisionRetentionInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentRevision|int|string $revision,
        public bool $keepForever,
    ) {}
}
