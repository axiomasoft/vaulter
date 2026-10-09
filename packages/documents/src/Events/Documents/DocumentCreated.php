<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Models\Drive\Node;

/**
 * A kind=document node was created with its initial v1 version (§2.5 / M4a). Carries
 * both the node and the v1 version so a listener/webhook need not re-read either, and
 * the content validators the write produced (state_version, collab_epoch).
 */
final readonly class DocumentCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public DocumentRevision $version,
        public int $stateVersion,
        public int $epoch,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
