<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use DateTimeImmutable;

/**
 * Read-only view of a drive. Built by Vaulter; the constructor is not part of the
 * public contract.
 */
final readonly class DriveSnapshot
{
    /** @internal */
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $profile,
        public string $name,
        public string $status,
        public ?string $tenantId,
        public DateTimeImmutable $createdAt,
    ) {}
}
