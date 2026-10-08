<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use Vaulter\Enums\Drive\ScanStatus;

/** The current revision of a file node. */
final readonly class FileHeadSnapshot
{
    /** @internal */
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
