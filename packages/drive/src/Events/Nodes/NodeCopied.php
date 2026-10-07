<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A node was copied to a destination (§2.4). For files the copy references the
 * SAME blob through a new edge (dedup keeps one physical object); for folders the
 * whole subtree is deep-copied. $copy is the newly created destination root.
 */
final readonly class NodeCopied implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $source,
        public Node $copy,
        public int|string|null $actorId = null,
        ?string $eventId = null,
        public ?ActorRef $actor = null,
        public ?string $correlationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
