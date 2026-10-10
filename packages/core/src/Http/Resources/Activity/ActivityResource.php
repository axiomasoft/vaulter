<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Activity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;

/**
 * One activity entry after the visibility projection: what happened, to which node,
 * by whom and when. Network details of the request (IP, user agent) stay server-side;
 * fields the reader may not see arrive as null.
 *
 * @mixin ActivityEntrySnapshot
 */
final class ActivityResource extends JsonResource
{
    /** @param string $nodeType the morph type activity rows use for a node target */
    public function __construct(ActivityEntrySnapshot $activity, private readonly string $nodeType)
    {
        parent::__construct($activity);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var ActivityEntrySnapshot $activity */
        $activity = $this->resource;
        $data = new stdClass;
        foreach ($activity->context as $key => $value) {
            $data->{$key} = $value;
        }

        return [
            'id' => $activity->id,
            'type' => $activity->verb,
            'node_id' => $activity->targetType === $this->nodeType ? $activity->targetId : null,
            'actor' => $activity->actorType === null && $activity->actorId === null ? null : ['type' => $activity->actorType, 'id' => $activity->actorId],
            'occurred_at' => SnapshotValue::iso($activity->createdAt),
            'data' => $data,
        ];
    }
}
