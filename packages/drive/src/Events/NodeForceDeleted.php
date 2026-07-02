<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A node and its subtree were permanently soft-deleted (deleted_at set), their
 * version↔blob edges detached so the blobs become GC-eligible (M1b GC reclaims
 * the bytes — never deleted directly here, §2.4 / §2.3). used_bytes was
 * decremented by the freed live size. The node objects are gone, so only the ids
 * and the reclaimed byte total travel with the event.
 *
 * Dispatched inside the force-delete transaction, so — like every sibling Drive
 * event — it implements ShouldDispatchAfterCommit: listeners (GC scheduling,
 * notifications) only fire once the soft-delete + edge detach is durable, and
 * never on a rolled-back attempt.
 *
 * @property list<int|string> $nodeIds
 */
final readonly class NodeForceDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int|string> $nodeIds
     */
    public function __construct(
        public int|string $storageId,
        public array $nodeIds,
        public int $freedBytes,
        public int|string|null $actorId = null,
    ) {}
}
