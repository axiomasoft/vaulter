<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Models\Drive\Node;

/**
 * A subject's grants on a node were revoked (§2.6 / H.2). The grant rows are gone,
 * so the event carries the node and the subject (a value object) plus how many
 * rows were removed. Dispatched INSIDE the revoke transaction (F-P1.7-03) so the
 * outbox/activity rows stage atomically with the revoke.
 */
final readonly class NodeGrantRevoked
{
    public function __construct(
        public Node $node,
        public Subject $subject,
        public int $revoked,
        public int|string|null $actorId = null,
    ) {}
}
