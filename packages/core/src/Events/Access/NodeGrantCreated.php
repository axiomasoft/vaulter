<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Access\NodeGrant;

/**
 * A per-node ACL grant was created or updated (§2.6 / H.2). Dispatched after the
 * grant transaction commits; downstream may bump an acl_version cache or record
 * activity.
 */
final readonly class NodeGrantCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NodeGrant $grant,
        public int|string|null $actorId = null,
    ) {}
}
