<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Node;
use Vaulter\Models\NodeDocumentVersion;

/**
 * A NAMED document version was snapshotted, or a prior version restored to head
 * (§2.5 / M4a). $restored distinguishes the two: false for a fresh named snapshot,
 * true when a restore repointed the head to a copy of a prior version.
 */
final readonly class DocumentVersionCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public NodeDocumentVersion $version,
        public bool $restored = false,
        public int|string|null $actorId = null,
    ) {}
}
