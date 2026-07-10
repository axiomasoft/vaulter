<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Vaulter\Models\Sharing\NodeShare;

/**
 * A bearer share-link was minted on a node (§2.7 / M2c). Carries the persisted
 * NodeShare (token_hash only — never the raw token) and the actor who created it.
 * Dispatched INSIDE the mint transaction (F-P1.7-03) so the outbox/activity rows stage
 * atomically with the share — the listener sees the row the same transaction wrote.
 */
final readonly class NodeShareCreated
{
    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
    ) {}
}
