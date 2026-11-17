<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/** The head of a document node, supplied by the documents package. */
#[Api]
final readonly class DocumentHeadSnapshot
{
    #[Internal]
    public function __construct(
        public string $type,
        public int $schemaVersion,
        public bool $typeAvailable,
        public ?string $headRevisionId,
        public int $epoch,
        public int $stateVersion,
    ) {}
}
