<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\DriveStatus;

/**
 * Read-only view of a drive. Built by Vaulter; the constructor is not part of the
 * public contract. `version` rises with each archive, unarchive and delete: pass it
 * back as the expected version of the next one.
 */
#[Api]
final readonly class DriveSnapshot
{
    #[Internal]
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $profile,
        public string $name,
        public DriveStatus $status,
        public ?string $tenantId,
        public DateTimeImmutable $createdAt,
        public int $version = 0,
    ) {}
}
