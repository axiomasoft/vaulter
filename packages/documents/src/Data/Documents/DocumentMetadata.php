<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/** Document identity and concurrency counters without loading document content. */
#[Api]
final readonly class DocumentMetadata
{
    #[Internal]
    public function __construct(
        public string $documentId,
        public string $type,
        public string $writeMode,
        public int $schemaVersion,
        public int $generation,
        public int $stateVersion,
    ) {}
}
