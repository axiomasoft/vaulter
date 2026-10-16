<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\NodeKind;

/**
 * Read-only view of a node. Identifiers are strings; pass `$node->id` (or the
 * snapshot itself) back to any operation. Actor fields hold the host key of the
 * user, or null for the system.
 */
#[Api]
final readonly class NodeSnapshot
{
    /**
     * @param array<string, array<string, string>> $properties namespace => key => value
     */
    #[Internal]
    public function __construct(
        public string $id,
        public string $driveId,
        public ?string $parentId,
        public NodeKind $kind,
        public string $name,
        public ?string $mimeType,
        public ?string $extension,
        public int $sizeBytes,
        public int $sequence,
        public bool $inheritsAccess,
        public array $properties,
        public DateTimeImmutable $createdAt,
        public ?string $createdBy,
        public DateTimeImmutable $updatedAt,
        public ?string $updatedBy,
        public ?DateTimeImmutable $contentModifiedAt,
        public ?string $contentModifiedBy,
        public ?DateTimeImmutable $trashedAt,
        public ?string $trashedBy,
        public bool $trashRoot,
        public ?FileHeadSnapshot $file,
        public ?DocumentHeadSnapshot $document,
        public ?ViewerStateSnapshot $viewer,
    ) {}
}
