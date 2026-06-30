<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Sharing\NodeShare;

/**
 * A bearer share-link was minted on a node (§2.7 / M2c). Carries the persisted
 * NodeShare (token_hash only — never the raw token) and the actor who created it.
 * Dispatched after commit so a downstream listener never sees an uncommitted row.
 */
final readonly class NodeShareCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
    ) {}
}
