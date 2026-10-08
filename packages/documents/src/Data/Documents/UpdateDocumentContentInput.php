<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Models\Drive\Node;

/**
 * Command for UpdateDocumentContentAction (§2.5 / M4a) — the autosave path: write
 * the document's working state. An autosave checkpoint follows on its own interval.
 * expectedStateVersion is the content precondition (412 document_state_mismatch).
 * yjsState carries the optional binary Yjs CRDT update (the M4b realtime source of
 * truth). content is already decoded by the document's type, in $schemaVersion.
 */
final readonly class UpdateDocumentContentInput
{
    public function __construct(
        public Node $node,
        public DocumentContent $content,
        public ?string $yjsState = null,
        public ?int $expectedStateVersion = null,
        public int $schemaVersion = 1,
    ) {}
}
