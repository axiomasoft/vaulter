<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Packman\Attributes\Internal;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/**
 * The outcome of a working-state write: whether anything changed, the document as
 * written, the checkpoint the write created (if any) and whether the collaboration
 * epoch moved (open rooms are then stale).
 */
#[Internal]
final readonly class DocumentStateResult
{
    #[Internal]
    public function __construct(
        public bool $changed,
        public Document $document,
        public ?DocumentRevision $checkpoint,
        public bool $epochChanged,
    ) {}
}
