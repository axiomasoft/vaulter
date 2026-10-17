<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\ScanStatus;

/** The current revision of a file node. */
#[Api]
final readonly class FileHeadSnapshot
{
    #[Internal]
    public function __construct(
        public string $fileId,
        public string $revisionId,
        public int $revisionNumber,
        public string $sha256,
        public int $sizeBytes,
        public ?string $mimeType,
        public ScanStatus $scanStatus,
    ) {}
}
