<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Export;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/**
 * Command for ExportDocumentAction: render $node in $format, from $revision (a
 * checkpoint the caller already resolved inside this node's document) or, when null,
 * from the working state.
 */
#[Internal]
final readonly class ExportDocumentInput
{
    #[Internal]
    public function __construct(
        public Node $node,
        public string $format,
        public ?DocumentRevision $revision = null,
    ) {}
}
