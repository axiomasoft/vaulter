<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Drive\Models\Node;

/**
 * Command for UpdateDocumentContentAction — the autosave path: write
 * the document's working state. An autosave checkpoint follows on its own interval.
 * expectedStateVersion is the content precondition (412 document_state_mismatch).
 * content is already decoded by the installed type. Binary state never enters this
 * ordinary JSON update; a collaborative replace explicitly reseeds a fresh generation.
 */
#[Api]
final readonly class UpdateDocumentContentInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentContent $content,
        public ?int $expectedStateVersion = null,
    ) {}
}
