<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

/**
 * What one ingest committed: the file node and the revision this very call created.
 * The file head is shared mutable state — a later writer may move it before the
 * caller returns — so callers needing "my revision" read it here, never from the head.
 */
#[Api]
final readonly class IngestReceipt
{
    #[Internal]
    public function __construct(
        public Node $node,
        public FileRevision $revision,
    ) {}
}
