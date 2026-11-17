<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * Read-only view of one immutable checkpoint of a document. A checkpoint never changes
 * after it is written except for its retention pin, so its id always names the same
 * content.
 */
#[Api]
final readonly class DocumentRevisionSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $documentId,
        public string $nodeId,
        public string $type,
        public int $number,
        public int $schemaVersion,
        public int $stateVersion,
        public ?string $label,
        public bool $isAutosave,
        public bool $keepForever,
        public bool $isHead,
        public ?string $restoredFromRevisionId,
        public ?string $contentHash,
        public ?int $contentSize,
        public ?int $collabStateSize,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
        public ?string $collabProtocol = null,
        public ?string $collabStateFormat = null,
        public int $epoch = 0,
    ) {}
}
