<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * A document was restored to an earlier checkpoint. Its working state is now a copy
 * of $restoredFrom, recorded as the new checkpoint $version, and the collaboration
 * epoch moved on: rooms opened before the restore are refused on their next store and
 * their clients must reopen the document. $epoch and $stateVersion are the values
 * after the restore.
 */
final readonly class DocumentRestored implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public DocumentRevision $restoredFrom,
        public int $epoch,
        public int $stateVersion,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
