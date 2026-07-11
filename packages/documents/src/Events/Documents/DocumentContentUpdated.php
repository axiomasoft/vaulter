<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * A document node's content was written (§2.5 / M4a) — the autosave path. $created
 * is true when a NEW autosave version was appended, false when the head autosave
 * version was updated in place within the debounce window.
 */
final readonly class DocumentContentUpdated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public NodeDocumentVersion $version,
        public bool $created,
        public int|string|null $actorId = null,
    ) {}
}
