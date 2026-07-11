<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Versions;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

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
