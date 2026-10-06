<?php

declare(strict_types=1);

namespace Vaulter\Activity;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\RequestContext;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Writes EXACTLY ONE activity row from a domain event (master plan §2.9 /
 * M2c-activity). It maps a known event into a {@see RecordActivityCommand}
 * (verb, actor, target morph, storage, context) and hands it to the
 * ActivityStoreRepository.
 *
 * Kernel domains (Access grants, Sharing links) map their own events here; the Drive
 * behaviour package (P2 #5 / ADR-0015) maps its file/folder/upload events through the
 * PUBLIC {@see record()} / {@see recordForNode()} seam — core no longer references any
 * Vaulter\Drive\Events\* class, so the audit feed keeps its identical one-row contract
 * whether or not vaulter-drive is installed.
 *
 * The actor is taken from the event payload (every Drive/kernel event carries the
 * acting user's id) with a fallback to the authenticated host user; the actor MORPH
 * TYPE comes from that authenticated user. ip / user-agent / request-id are captured
 * from the current request via {@see RequestContext}, degrading to null off the HTTP
 * lifecycle (console/queue).
 *
 * This is the anti-NC-fan-out core (§1.5): ONE row per event, never a per-affected-
 * user fan-out. Recipients are resolved at READ by scoping the feed.
 */
final readonly class ActivityRecorder
{
    public function __construct(
        private ActivityStoreRepository $store,
        private MetricsRecorder $metrics,
    ) {}

    public function recordGrantCreated(NodeGrantCreated $event): Activity
    {
        $node = $event->grant->node;

        return $this->record(
            verb: EventType::GrantCreated,
            storageId: $node->drive_id,
            tenantId: $event->grant->tenant_id,
            actorId: $event->actorId,
            actor: $event->actor,
            correlationId: $event->correlationId,
            target: $node,
            context: [
                'grant_id' => Identity::stringify($event->grant->getKey()),
                'subject_type' => $event->grant->subject_type,
                'subject_id' => Identity::stringify($event->grant->subject_id),
                'level' => $event->grant->level->value,
                'effect' => $event->grant->effect->value,
                'scope' => $event->grant->scope->value,
            ],
        );
    }

    public function recordGrantRevoked(NodeGrantRevoked $event): Activity
    {
        return $this->recordForNode(
            verb: EventType::GrantRevoked,
            node: $event->node,
            actorId: $event->actorId,
            actor: $event->actor,
            correlationId: $event->correlationId,
            context: [
                'subject_type' => $event->subject->type,
                'subject_id' => Identity::stringify($event->subject->id),
                'revoked' => $event->revoked,
            ],
        );
    }

    public function recordShareCreated(NodeShareCreated $event): Activity
    {
        $node = $event->share->node;

        return $this->record(
            verb: EventType::ShareCreated,
            storageId: $node->drive_id,
            tenantId: $event->share->tenant_id,
            actorId: $event->actorId,
            actor: $event->actor,
            correlationId: $event->correlationId,
            target: $node,
            context: [
                'share_id' => Identity::stringify($event->share->getKey()),
                'token_prefix' => $event->share->token_prefix,
                'level' => $event->share->level->value,
                'scope' => $event->share->scope->value,
                'permissions' => $event->share->permissions,
            ],
        );
    }

    public function recordShareRevoked(NodeShareRevoked $event): Activity
    {
        $node = $event->share->node;

        return $this->record(
            verb: EventType::ShareRevoked,
            storageId: $node->drive_id,
            tenantId: $event->share->tenant_id,
            actorId: $event->actorId,
            actor: $event->actor,
            correlationId: $event->correlationId,
            target: $node,
            context: [
                'share_id' => Identity::stringify($event->share->getKey()),
                'token_prefix' => $event->share->token_prefix,
            ],
        );
    }

    /**
     * Build + persist a row whose target is a node (the common Drive case): the
     * storage scope and tenant are read from the node. This is the PUBLIC seam the
     * vaulter-drive event subscriber maps its node events through.
     *
     * @param array<string, mixed>|null $context
     */
    public function recordForNode(
        EventType $verb,
        Node $node,
        int|string|null $actorId,
        ?array $context = null,
        ?ActorRef $actor = null,
        ?string $correlationId = null,
    ): Activity {
        return $this->record(
            verb: $verb,
            storageId: $node->drive_id,
            tenantId: $node->tenant_id,
            actorId: $actorId,
            target: $node,
            context: $context,
            actor: $actor,
            correlationId: $correlationId,
        );
    }

    /**
     * Resolve the actor morph + request context, then persist EXACTLY ONE row. The
     * PUBLIC low-level seam: callers that have no node target (drive.created — the
     * target is the root folder; node.purged — the nodes are gone) pass the
     * scope scalars + optional target model directly.
     *
     * @param array<string, mixed>|null $context
     */
    public function record(
        EventType $verb,
        int|string|null $storageId = null,
        int|string|null $tenantId = null,
        int|string|null $actorId = null,
        ?Model $target = null,
        ?array $context = null,
        ?ActorRef $actor = null,
        ?string $correlationId = null,
    ): Activity {
        [$actorType, $resolvedActorId] = $actor === null ? $this->resolveActor($actorId) : [$actor->type, $actor->id];
        $request = RequestContext::capture();
        if ($correlationId !== null) {
            $context = [...($context ?? []), 'correlation_id' => $correlationId];
        }

        $activity = $this->store->record(new RecordActivityCommand(
            verb: $verb,
            tenantId: $tenantId,
            storageId: $storageId,
            actorType: $actorType,
            actorId: $resolvedActorId,
            targetType: $target?->getMorphClass(),
            targetId: $target === null ? null : Identity::key($target->getKey()),
            context: $actor?->reason === null ? $context : [...($context ?? []), 'system_reason' => $actor->reason],
            ip: $request->ip,
            userAgent: $request->userAgent,
            requestId: $request->requestId,
        ));

        $this->metrics->counter(
            name: 'vaulter_activity_write_total',
            labels: ['verb' => $verb->value],
        );

        return $activity;
    }

    /**
     * Resolve the (actor_type, actor_id) morph. The event payload's actorId wins
     * for the id; the morph type comes from the authenticated host user (events
     * carry only a scalar id, not the morph). When the event carries no id we fall
     * back to the authenticated user entirely. With neither, the actor is null (a
     * system write).
     *
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveActor(int|string|null $actorId): array
    {
        $user = $this->authenticatedUser();

        if ($actorId !== null) {
            // The morph type is the auth user's when its id matches; otherwise we
            // know the id but not a model, so the type stays null (still a valid,
            // queryable actor_id row).
            $type = $user !== null && Identity::stringify($user->getKey()) === Identity::stringify($actorId)
                ? $user->getMorphClass()
                : null;

            return [$type, $actorId];
        }

        if ($user !== null) {
            return [$user->getMorphClass(), Identity::key($user->getKey())];
        }

        return [null, null];
    }

    private function authenticatedUser(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
