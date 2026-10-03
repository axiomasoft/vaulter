<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Illuminate\Support\Str;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Models\Drive\Node;

/**
 * A subject's grants on a node were revoked (§2.6 / H.2). The grant rows are gone,
 * so the event carries the node and the subject (a value object) plus how many
 * rows were removed.
 *
 * BREAKING CONTRACT (intentional, MAJOR — review P0.2 / F-P1.7-03): no longer
 * ShouldDispatchAfterCommit; dispatched SYNCHRONOUSLY INSIDE the revoke transaction so
 * the outbox/activity rows stage atomically with the revoke. Listeners MUST be
 * synchronous and idempotent — never a queued/after-commit side-effect off this event
 * directly (drive that off the durable outbox relay). See NodeGrantCreated for the full
 * rationale and the Pending-Work note on an additive after-commit signal.
 */
final readonly class NodeGrantRevoked
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public Subject $subject,
        public int $revoked,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
