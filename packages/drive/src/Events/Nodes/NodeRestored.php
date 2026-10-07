<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A trashed node and its subtree were restored (§2.10): the trashed triplet is
 * cleared. The node may have been re-homed to root when its original parent was
 * gone.
 */
final readonly class NodeRestored implements ShouldDispatchAfterCommit
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
