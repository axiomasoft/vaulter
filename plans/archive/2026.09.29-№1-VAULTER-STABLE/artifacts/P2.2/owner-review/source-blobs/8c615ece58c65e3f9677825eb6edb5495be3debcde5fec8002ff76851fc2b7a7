<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Nodes;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Models\Drive\Node;

/**
 * A node was renamed in place (§2.2). Carries the previous name for the audit.
 */
final readonly class NodeRenamed implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public string $fromName,
        public string $toName,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
