<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Packman\Attributes\Internal;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;

/**
 * What one ingest committed: the file node and the revision this very call created.
 * The file head is shared mutable state — a later writer may move it before the
 * caller returns — so callers needing "my revision" read it here, never from the head.
 */
#[Internal]
final readonly class IngestReceipt
{
    #[Internal]
    public function __construct(
        public Node $node,
        public FileRevision $revision,
    ) {}
}
