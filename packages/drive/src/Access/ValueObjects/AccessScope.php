<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\ValueObjects;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Models\Drive;
use Vaulter\ValueObjects\Access\OwnerRef;

/** Node-blind identity coordinates for the selected profile's permission driver. */
#[Api]
final readonly class AccessScope
{
    public function __construct(
        public string $profile,
        public OwnerRef $driveOwner,
        public int|string $driveId,
        public int|string|null $tenantId = null,
    ) {}

    public static function forDrive(Drive $drive): self
    {
        return new self(
            profile: $drive->profile,
            driveOwner: new OwnerRef(type: $drive->owner_type, id: $drive->owner_id),
            driveId: $drive->id,
            tenantId: $drive->tenant_id,
        );
    }
}
