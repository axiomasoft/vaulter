<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Activity;

use Illuminate\Support\Carbon;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Activity\Activity;
use Vaulter\Schema\IdStrategyResolver;

/**
 * Write-side row work for v_activity (§2.9 / M2c-activity). It inserts EXACTLY ONE
 * append-only row from an already-resolved {@see RecordActivityCommand} and stamps
 * created_at itself (the model keeps timestamps off — append-only has no
 * updated_at). It opens NO transactions and dispatches NO events; the EventRecorder
 * owns the boundary. There is no update or delete method — by design.
 *
 * The row is unique by event_id: recording the same occurrence twice inserts it once,
 * and the database decides which writer wins.
 *
 * Not final: it is the recorder's single audit-sink seam, so a host (or a test
 * simulating a broken sink) may subclass it to route rows elsewhere.
 */
class ActivityStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly VaulterDatabase $database,
        private readonly IdStrategyResolver $ids,
    ) {}

    /**
     * Persist one activity row; false when a row for this occurrence already exists.
     * created_at is stamped here (now) so the feed's (created_at desc) order is
     * monotonic with the write.
     */
    public function record(RecordActivityCommand $command): bool
    {
        $model = $this->vaulterConfig->model('activity');

        // The model applies its casts (JSON context, dates) so the row matches what
        // Eloquent would have written.
        /** @var Activity $activity */
        $activity = new $model;
        $activity->forceFill([
            'tenant_id' => $command->tenantId,
            'drive_id' => $command->storageId,
            'actor_type' => $command->actorType,
            'actor_id' => $command->actorId,
            'verb' => $command->verb->value,
            'event_id' => $command->eventId,
            'target_type' => $command->targetType,
            'target_id' => $command->targetId,
            'context' => $command->context,
            'ip' => $command->ip,
            'user_agent' => $command->userAgent,
            'request_id' => $command->requestId,
            'created_at' => Carbon::now(),
        ]);
        $id = $this->ids->resolve()->newId();
        $row = $id === null ? $activity->getAttributes() : [$activity->getKeyName() => $id, ...$activity->getAttributes()];

        return $this->database->insertOnce(table: $activity->getTable(), row: $row, uniqueColumn: 'event_id');
    }
}
