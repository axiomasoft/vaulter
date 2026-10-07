<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A node and its whole subtree were soft-trashed (§2.10 / D17): the trashed
 * triplet is set atomically. used_bytes is NOT decremented — trashed bytes still
 * occupy storage until forceDelete.
 */
final readonly class NodeTrashed implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public int|string|null $actorId = null,
        ?string $eventId = null,
        public ?ActorRef $actor = null,
        public ?string $correlationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
