<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * A document node's content was written (§2.5 / M4a) — the autosave path. $created
 * is true when a NEW autosave version was appended, false when the head autosave
 * version was updated in place within the debounce window.
 */
final readonly class DocumentContentUpdated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public bool $created,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
