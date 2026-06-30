<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\NodeShare;

/**
 * A bearer share-link was revoked (§2.7 / M2c). A revoked share never resolves
 * again (the resolver filters on revoked_at). Carries the revoked NodeShare and
 * the actor who revoked it; dispatched after commit.
 */
final readonly class NodeShareRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
    ) {}
}
