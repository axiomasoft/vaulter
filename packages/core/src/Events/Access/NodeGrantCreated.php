<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Vaulter\Models\Access\NodeGrant;

/**
 * A per-node ACL grant was created or updated (§2.6 / H.2). Dispatched INSIDE the
 * grant transaction (F-P1.7-03) so the outbox/activity rows stage ATOMICALLY with the
 * grant — no crash window between commit and staging. The subscribers swallow their
 * own failures, so an audit/outbox error still never rolls the grant back.
 */
final readonly class NodeGrantCreated
{
    public function __construct(
        public NodeGrant $grant,
        public int|string|null $actorId = null,
    ) {}
}
