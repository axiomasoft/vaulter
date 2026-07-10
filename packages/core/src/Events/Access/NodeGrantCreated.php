<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Vaulter\Models\Access\NodeGrant;

/**
 * A per-node ACL grant was created or updated (§2.6 / H.2).
 *
 * BREAKING CONTRACT (intentional, MAJOR — review P0.2 / F-P1.7-03): this event no
 * longer implements ShouldDispatchAfterCommit. It is dispatched SYNCHRONOUSLY INSIDE
 * the grant transaction so the outbox/activity rows stage ATOMICALLY with the grant —
 * closing the crash window between commit and staging that the transactional-outbox
 * fix removes. Consequence for listeners: a listener on this event runs inside the open
 * transaction and MUST be synchronous and idempotent. Do NOT hang a queued
 * (ShouldQueue) or after-commit side-effect off this event directly — it would race or
 * outlive a rolled-back grant; drive such work off the durable outbox relay instead.
 * (An additive after-commit PUBLIC signal for external consumers is tracked as Pending
 * Work in phases/P0.md P0.2.)
 */
final readonly class NodeGrantCreated
{
    public function __construct(
        public NodeGrant $grant,
        public int|string|null $actorId = null,
    ) {}
}
