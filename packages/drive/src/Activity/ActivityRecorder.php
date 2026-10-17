<?php

declare(strict_types=1);

namespace Vaulter\Drive\Activity;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Data\Activity\RecordActivityCommand;
use Vaulter\Drive\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\RequestContext;

/**
 * Writes EXACTLY ONE activity row for a domain event. It maps the event into a {@see RecordActivityCommand} (occurrence
 * id, verb, actor, target morph, storage, context) and hands it to the
 * ActivityStoreRepository. Only {@see ActivitySink} calls it, inside the
 * transaction of the Action that caused the event.
 *
 * ip / user-agent / request-id are captured from the current request via
 * {@see RequestContext}, degrading to null off the HTTP lifecycle (console/queue).
 *
 * This is the anti-NC-fan-out core: ONE row per event, never a per-affected-
 * user fan-out. Recipients are resolved at READ by scoping the feed.
 */
#[Internal]
final readonly class ActivityRecorder
{
    public function __construct(
        private ActivityStoreRepository $store,
        private MetricsRecorder $metrics,
    ) {}

    /** False when the occurrence already has its row. */
    public function record(DomainEvent $event): bool
    {
        $scope = $event->scope();
        $target = $event->activityTarget();
        $request = RequestContext::capture();
        $context = [...$event->activityContext(), 'correlation_id' => $event->correlationId];
        if ($event->actor->reason !== null) {
            $context['system_reason'] = $event->actor->reason;
        }

        $inserted = $this->store->record(new RecordActivityCommand(
            eventId: $event->eventId,
            verb: $event->type(),
            tenantId: $scope->tenantId,
            storageId: $scope->driveId,
            actorType: $event->actor->type,
            actorId: $event->actor->id,
            targetType: $target?->type,
            targetId: $target?->id,
            context: $context,
            ip: $request->ip,
            userAgent: $request->userAgent,
            requestId: $request->requestId,
        ));

        if ($inserted) {
            $this->metrics->counter(
                name: 'vaulter_activity_write_total',
                labels: ['verb' => $event->type()->value],
            );
        }

        return $inserted;
    }
}
