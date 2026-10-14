<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Activity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Events\EventTypeContract;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side queries over v_activity. The feed is scoped to a
 * storage / node / actor — this IS "recipients resolved at read": there are no
 * notification rows and no per-user fan-out, just a scoped, newest-first query the
 * controller cursor-paginates.
 *
 * Every method returns a Builder already ordered newest-first (created_at desc, id
 * desc), so the consumer only attaches the cursor. An optional verb filter narrows
 * any scope.
 */
final class ActivityReadRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * The activity feed for a whole storage tree, newest-first. Optionally narrowed
     * to a single verb. (verb, created_at) and (drive_id, created_at) are indexed.
     *
     * @return Builder<Activity>
     */
    public function forStorage(Drive $storage, ?EventTypeContract $verb = null): Builder
    {
        return $this->base($verb)->where('drive_id', $storage->getKey());
    }

    /**
     * The activity feed for a single node (events whose TARGET is the node),
     * newest-first. Optionally narrowed to a verb. (target_type, target_id,
     * created_at) is indexed.
     *
     * @return Builder<Activity>
     */
    public function forNode(Node $node, ?EventTypeContract $verb = null): Builder
    {
        return $this->forTarget($node, $verb);
    }

    /**
     * The activity feed for an arbitrary target model (the target morph), newest-
     * first. forNode delegates here; a host may feed any Vaulter target.
     *
     * @return Builder<Activity>
     */
    public function forTarget(Model $target, ?EventTypeContract $verb = null): Builder
    {
        return $this->base($verb)
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey());
    }

    /**
     * The activity an actor performed (the actor morph), newest-first. Optionally
     * narrowed to a verb. (actor_type, actor_id, created_at) is indexed.
     *
     * @return Builder<Activity>
     */
    public function forActor(string $actorType, int|string $actorId, ?EventTypeContract $verb = null): Builder
    {
        return $this->base($verb)
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId);
    }

    /**
     * The whole feed for a single verb across all scopes, newest-first. (verb,
     * created_at) is indexed.
     *
     * @return Builder<Activity>
     */
    public function byVerb(EventTypeContract $verb): Builder
    {
        return $this->base($verb);
    }

    /**
     * The newest-first base query with an optional verb filter applied.
     *
     * @return Builder<Activity>
     */
    private function base(?EventTypeContract $verb): Builder
    {
        $model = $this->vaulterConfig->model('activity');

        $query = app(TenancyBoundary::class)->constrain($model::query())->newestFirst();

        if ($verb !== null) {
            $query->where('verb', $verb->value);
        }

        return $query;
    }
}
