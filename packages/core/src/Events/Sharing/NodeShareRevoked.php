<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Vaulter\Models\Sharing\NodeShare;

/**
 * A bearer share-link was revoked (§2.7 / M2c). A revoked share never resolves
 * again (the resolver filters on revoked_at). Carries the revoked NodeShare and
 * the actor who revoked it. Dispatched INSIDE the revoke transaction (F-P1.7-03) so
 * the outbox/activity rows stage atomically with the revoke.
 */
final readonly class NodeShareRevoked
{
    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
    ) {}
}
