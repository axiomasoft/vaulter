<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\ScanStatus;

/** Read-only view of one revision of a file. */
#[Api]
final readonly class FileRevisionSnapshot
{
    #[Internal]
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
