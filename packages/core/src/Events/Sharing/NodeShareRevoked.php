<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Vaulter\Models\Sharing\NodeShare;

/**
 * A bearer share-link was revoked (§2.7 / M2c). A revoked share never resolves
 * again (the resolver filters on revoked_at). Carries the revoked NodeShare and
 * the actor who revoked it.
 *
 * BREAKING CONTRACT (intentional, MAJOR — review P0.2 / F-P1.7-03): no longer
 * ShouldDispatchAfterCommit; dispatched SYNCHRONOUSLY INSIDE the revoke transaction so
 * the outbox/activity rows stage atomically with the revoke. Listeners MUST be
 * synchronous and idempotent — never a queued/after-commit side-effect off this event
 * directly (drive that off the durable outbox relay). See NodeGrantCreated for the full
 * rationale and the Pending-Work note on an additive after-commit signal.
 */
final readonly class NodeShareRevoked
{
    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
    ) {}
}
