<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Models\Drive\Node;

/**
 * Command for UpdateDocumentContentAction (§2.5 / M4a) — the autosave path: write
 * the document's working state. An autosave checkpoint follows on its own interval.
 * expectedStateVersion is the content precondition (412 document_state_mismatch).
 * yjsState carries the optional binary Yjs CRDT update (the M4b realtime source of
 * truth). content is the handler's payload — a TipTap doc for rich-text handlers, or
 * an opaque JSON snapshot for non-rich-text ones (e.g. a spreadsheet workbook).
 */
final readonly class UpdateDocumentContentInput
{
    public function __construct(
        public Node $node,
        public DocumentContent $content,
        public ?string $yjsState = null,
        public int|string|null $actorId = null,
        public ?int $expectedStateVersion = null,
    ) {}
}
