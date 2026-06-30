<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Activity;

use Illuminate\Support\Carbon;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Models\Activity\Activity;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_activity (§2.9 / M2c-activity). It inserts EXACTLY ONE
 * append-only row from an already-resolved {@see RecordActivityCommand} and stamps
 * created_at itself (the model keeps timestamps off — append-only has no
 * updated_at). It opens NO transactions and dispatches NO events; the recorder /
 * subscriber owns the boundary. There is no update or delete method — by design.
 *
 * Not final: it is the recorder's single audit-sink seam, so a host (or a test
 * simulating a broken sink) may subclass it to route rows elsewhere.
 */
class ActivityStoreRepository
{
    /**
     * Persist one activity row. created_at is stamped here (now) so the feed's
     * (created_at desc) order is monotonic with the write.
     */
    public function record(RecordActivityCommand $command): Activity
    {
        $model = Config::activityModel();

        /** @var Activity $activity */
        $activity = new $model;
        $activity->forceFill([
            'tenant_id' => $command->tenantId,
            'storage_id' => $command->storageId,
            'actor_type' => $command->actorType,
            'actor_id' => $command->actorId,
            'verb' => $command->verb->value,
            'target_type' => $command->targetType,
            'target_id' => $command->targetId,
            'context' => $command->context,
            'ip' => $command->ip,
            'user_agent' => $command->userAgent,
            'request_id' => $command->requestId,
            'created_at' => Carbon::now(),
        ]);
        $activity->save();

        return $activity;
    }
}
