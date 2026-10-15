<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots\Mapping;

use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\DriveSnapshot;
use Vaulter\Data\Snapshots\QuotaSnapshot;
use Vaulter\Enums\Drive\QuotaState;
use Vaulter\Models\Drive\Drive;
use Vaulter\Quota\QuotaCalculator;

/** @internal */
#[Internal]
final readonly class DriveSnapshotMapper
{
    public function __construct(private QuotaCalculator $quota) {}

    public function map(Drive $drive): DriveSnapshot
    {
        return new DriveSnapshot(
            id: SnapshotValue::id($drive->getKey()),
            ownerType: $drive->owner_type,
            ownerId: SnapshotValue::id($drive->owner_id),
            profile: $drive->profile,
            name: $drive->name,
            status: $drive->status,
            tenantId: SnapshotValue::nullableId($drive->tenant_id),
            createdAt: SnapshotValue::requiredTime($drive->created_at),
        );
    }

    public function quota(Drive $drive): QuotaSnapshot
    {
        $limit = $drive->quota_bytes;
        $used = $drive->used_bytes;

        return new QuotaSnapshot(
            limitBytes: $limit,
            usedBytes: $used,
            trashedBytes: $this->quota->trashedBytes($drive->id),
            remainingBytes: $limit === null ? null : max(0, $limit - $used),
            state: QuotaState::for($limit, $used),
        );
    }
}
