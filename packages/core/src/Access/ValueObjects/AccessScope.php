<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Models\Drive\Drive;
use Vaulter\ValueObjects\Access\OwnerRef;

/** Node-blind identity coordinates for the selected profile's permission driver. */
final readonly class AccessScope
{
    public function __construct(
        public string $profile,
        public OwnerRef $driveOwner,
        public int|string $driveId,
        public ?Subject $nodeOwner = null,
        public int|string|null $tenantId = null,
    ) {}

    public static function forDrive(Drive $drive, ?Subject $nodeOwner = null): self
    {
        return new self(
            profile: $drive->profile,
            driveOwner: new OwnerRef(type: $drive->owner_type, id: $drive->owner_id),
            driveId: $drive->id,
            nodeOwner: $nodeOwner,
            tenantId: $drive->tenant_id,
        );
    }
}
