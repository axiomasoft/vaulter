<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Revisions;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * A NAMED document version was snapshotted, or a prior version restored to head
 * (§2.5 / M4a). $restored distinguishes the two: false for a fresh named snapshot,
 * true when a restore repointed the head to a copy of a prior version. $stateVersion and
 * $epoch are the document's content validators at that moment; a named snapshot moves
 * neither.
 */
final readonly class DocumentRevisionCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public int $stateVersion,
        public int $epoch,
        public bool $restored = false,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
