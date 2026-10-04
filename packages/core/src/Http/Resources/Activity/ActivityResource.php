<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Activity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Activity\Activity;

/**
 * The read shape of an activity row (§2.9 / M2c-activity). It exposes the verb, the
 * actor + target morphs, the storage scope, the structured context and the request
 * meta (ip / user-agent / request-id) for the audit surface. created_at is the feed
 * cursor; there is no updated_at (append-only).
 *
 * @mixin Activity
 */
final class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;

        return [
            'id' => $activity->getKey(),
            'verb' => $activity->verb->value,
            'storage_id' => $activity->drive_id,
            'actor_type' => $activity->actor_type,
            'actor_id' => $activity->actor_id,
            'target_type' => $activity->target_type,
            'target_id' => $activity->target_id,
            'context' => $activity->context,
            'ip' => $activity->ip,
            'user_agent' => $activity->user_agent,
            'request_id' => $activity->request_id,
            'created_at' => $activity->created_at,
        ];
    }
}
