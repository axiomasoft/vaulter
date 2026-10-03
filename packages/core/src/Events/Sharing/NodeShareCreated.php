<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Illuminate\Support\Str;
use Vaulter\Models\Sharing\NodeShare;

/**
 * A bearer share-link was minted on a node (§2.7 / M2c). Carries the persisted
 * NodeShare (token_hash only — never the raw token) and the actor who created it.
 *
 * BREAKING CONTRACT (intentional, MAJOR — review P0.2 / F-P1.7-03): no longer
 * ShouldDispatchAfterCommit; dispatched SYNCHRONOUSLY INSIDE the mint transaction so
 * the outbox/activity rows stage atomically with the share — the listener sees the row
 * the same transaction wrote. Listeners MUST be synchronous and idempotent — never a
 * queued/after-commit side-effect off this event directly (drive that off the durable
 * outbox relay). See NodeGrantCreated for the full rationale and the Pending-Work note
 * on an additive after-commit signal.
 */
final readonly class NodeShareCreated
{
    public string $eventId;

    public function __construct(
        public NodeShare $share,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
