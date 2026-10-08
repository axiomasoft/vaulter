<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;

/**
 * Read-only view of one immutable checkpoint of a document. A checkpoint never changes
 * after it is written except for its retention pin, so its id always names the same
 * content.
 */
final readonly class DocumentRevisionSnapshot
{
    /** @internal */
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
        public ?int $yjsStateSize,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
    ) {}
}
