<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\NodeKind;

/**
 * Read-only view of a node. Identifiers are strings; pass `$node->id` (or the
 * snapshot itself) back to any operation. Actor fields hold the host key of the
 * user, or null for the system.
 *
 * `sequence` versions the node itself; `accessVersion` versions its access (its
 * grants and inheritance; they never move the sequence) — pass it back as the expected access version of
 * a revoke or an inheritance change.
 *
 * `syncKey` is the opaque handle of this node for the reader it was mapped for:
 * the change feed names a removed node by it. It is null for a reader without a sync
 * identity (anonymous, share) and is never part of a persisted receipt.
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
        public int $accessVersion = 0,
        public ?string $syncKey = null,
    ) {}

    /** The same snapshot with the sync key of the reader it is returned to (a replayed receipt has none). */
    public function withSyncKey(?string $syncKey): self
    {
        return new self(
            $this->id, $this->driveId, $this->parentId, $this->kind, $this->name, $this->mimeType, $this->extension,
            $this->sizeBytes, $this->sequence, $this->inheritsAccess, $this->properties, $this->createdAt, $this->createdBy,
            $this->updatedAt, $this->updatedBy, $this->contentModifiedAt, $this->contentModifiedBy, $this->trashedAt,
            $this->trashedBy, $this->trashRoot, $this->file, $this->document, $this->viewer, $this->accessVersion, $syncKey,
        );
    }
}
