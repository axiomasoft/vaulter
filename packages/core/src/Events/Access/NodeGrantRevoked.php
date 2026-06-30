<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Models\Drive\Node;

/**
 * A subject's grants on a node were revoked (§2.6 / H.2). The grant rows are gone,
 * so the event carries the node and the subject (a value object) plus how many
 * rows were removed.
 */
final readonly class NodeGrantRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public Subject $subject,
        public int $revoked,
        public int|string|null $actorId = null,
    ) {}
}
