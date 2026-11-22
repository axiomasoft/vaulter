<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Drive\Models\Node;

/**
 * Command for UpdateDocumentContentAction — the autosave path: write
 * the document's working state. An autosave checkpoint follows on its own interval.
 * expectedStateVersion is the content precondition (412 document_state_mismatch).
 * The installed type decodes the input against the current state under the write lock.
 */
#[Internal]
final readonly class UpdateDocumentContentInput
{
    /**
     * @param DocumentContent|array<string, mixed> $content
     * @param (Closure(string): bool)|null         $matchesTag
     */
    #[Internal]
    public function __construct(
        public Node $node,
        public DocumentContent|array $content,
        public ?int $expectedStateVersion = null,
        public ?Closure $matchesTag = null,
    ) {}
}
