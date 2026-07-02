<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\DocDrive\Models\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * A kind=document node was created with its initial v1 version (§2.5 / M4a). Carries
 * both the node and the v1 version so a listener/webhook need not re-read either.
 */
final readonly class DocumentNodeCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Node $node,
        public NodeDocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
