<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Enums\Drive\ScanStatus;

/** Read-only view of one revision of a file. */
final readonly class FileRevisionSnapshot
{
    /** @internal */
    public function __construct(
        public string $id,
        public string $fileNodeId,
        public int $number,
        public int $sizeBytes,
        public string $sha256,
        public ?string $mimeType,
        public ?string $declaredMimeType,
        public ScanStatus $scanStatus,
        public bool $keepForever,
        public bool $isHead,
        public ?string $restoredFromRevisionId,
        public ?DateTimeImmutable $clientModifiedAt,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
    ) {}
}
