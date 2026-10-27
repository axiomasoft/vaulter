<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Revisions;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/**
 * Command for RestoreDocumentRevisionAction: copy a checkpoint of the node's document
 * forward into the working state. The revision is resolved again inside the node's own
 * aggregate under the document lock, so a key of another document never restores.
 * expectedStateVersion is the content precondition (412 document_state_mismatch).
 */
#[Internal]
final readonly class RestoreDocumentRevisionInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentRevision|int|string $revision,
        public ?int $expectedStateVersion = null,
    ) {}
}
