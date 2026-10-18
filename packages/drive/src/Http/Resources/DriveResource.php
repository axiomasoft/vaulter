<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\QuotaSnapshot;

/**
 * A drive as the versioned API shows it: who owns it, its profile and lifecycle status,
 * the root folder a client opens into and the quota accounting. What the caller may do
 * there is the root node's abilities, read from the node itself. `etag` is the drive's
 * tag (id and version), the If-Match of its archive, unarchive and delete.
 */
#[Internal]
final class DriveResource extends JsonResource
{
    public function __construct(DriveSnapshot $drive, private readonly QuotaSnapshot $quota, private readonly string $rootNodeId)
    {
        parent::__construct($drive);
    }

    /** The ETag header value: the same tag the body carries (the drive's id and version). */
    public function etag(): string
    {
        /** @var DriveSnapshot $drive */
        $drive = $this->resource;

        return AggregatePreconditions::driveTag($drive);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DriveSnapshot $drive */
        $drive = $this->resource;

        return [
            'id' => $drive->id,
            'owner' => ['type' => $drive->ownerType, 'id' => $drive->ownerId],
            'profile' => $drive->profile,
            'name' => $drive->name,
            'root_node_id' => $this->rootNodeId,
            'status' => $drive->status->value,
            'version' => $drive->version,
            'etag' => $this->etag(),
            'quota_bytes' => $this->quota->limitBytes,
            'used_bytes' => $this->quota->usedBytes,
            'created_at' => SnapshotValue::iso($drive->createdAt),
        ];
    }
}
