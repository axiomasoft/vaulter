<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots\Mapping;

use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Models\Activity\Activity;

/** @internal Maps rows already projected by VisibleActivityQuery. */
final readonly class ActivitySnapshotMapper
{
    public function map(Activity $activity): ActivityEntrySnapshot
    {
        $context = $activity->context;

        return new ActivityEntrySnapshot(
            id: SnapshotValue::id($activity->getKey()),
            verb: $activity->verb->value,
            driveId: SnapshotValue::nullableId($activity->drive_id),
            targetType: $activity->target_type,
            targetId: SnapshotValue::nullableId($activity->target_id),
            actorType: $activity->actor_type,
            actorId: SnapshotValue::nullableId($activity->actor_id),
            context: is_array($context) ? $context : [],
            ip: $activity->ip,
            userAgent: $activity->user_agent,
            requestId: $activity->request_id,
            createdAt: SnapshotValue::requiredTime($activity->created_at),
        );
    }
}
