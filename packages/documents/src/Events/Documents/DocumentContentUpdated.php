<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * A document node's content was written (§2.5 / M4a) — the autosave path. $created
 * is true when this write took a new checkpoint; false when the head checkpoint stayed
 * as it was (the checkpoint interval had not passed), so $version then predates the
 * write. $stateVersion and $epoch are the content validators after this write, taken
 * from the writer's result — use them, not $version, for the state of the document.
 */
final readonly class DocumentContentUpdated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public bool $created,
        public int $stateVersion,
        public int $epoch,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
