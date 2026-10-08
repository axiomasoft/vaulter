<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Activity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;

/**
 * The read shape of an activity row (§2.9 / M2c-activity). It exposes the verb, the
 * actor + target morphs, the storage scope, the structured context and the request
 * meta (ip / user-agent / request-id) for the audit surface. created_at is the feed
 * cursor; there is no updated_at (append-only).
 *
 * @mixin ActivityEntrySnapshot
 */
final class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var ActivityEntrySnapshot $activity */
        $activity = $this->resource;

        return [
            'id' => $activity->id,
            'verb' => $activity->verb,
            'storage_id' => $activity->driveId,
            'actor_type' => $activity->actorType,
            'actor_id' => $activity->actorId,
            'target_type' => $activity->targetType,
            'target_id' => $activity->targetId,
            'context' => $activity->context === [] ? null : $activity->context,
            'ip' => $activity->ip,
            'user_agent' => $activity->userAgent,
            'request_id' => $activity->requestId,
            'created_at' => SnapshotValue::iso($activity->createdAt),
        ];
    }
}
