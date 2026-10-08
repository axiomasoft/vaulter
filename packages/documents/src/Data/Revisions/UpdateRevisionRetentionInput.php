<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * Command for UpdateRevisionRetentionAction: pin a checkpoint of the node's document
 * out of the prune window (keepForever) or release the pin. The revision is resolved
 * inside the node's own aggregate.
 */
final readonly class UpdateRevisionRetentionInput
{
    public function __construct(
        public Node $node,
        public DocumentRevision|int|string $revision,
        public bool $keepForever,
    ) {}
}
