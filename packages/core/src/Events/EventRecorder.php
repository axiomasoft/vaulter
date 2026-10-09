<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Throwable;
use Vaulter\Activity\ActivityRecorder;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Webhooks\EventEnvelope;
use Vaulter\Webhooks\OutboxRecorder;
use WeakMap;

/**
 * The only way a domain event is recorded. Called as the last step of an Action's
 * transaction, it writes the activity row and the outbox row of the occurrence on the
 * Vaulter connection, then hands the event to host listeners after the commit.
 *
 * A failed write is not caught here: it leaves the transaction, which rolls the change
 * back together with whatever was already recorded, so a committed change always has
 * its rows. Host listeners are the opposite: they run after the commit, and their
 * failure is reported without undoing a change that is already durable.
 */
final readonly class EventRecorder
{
    /** @var WeakMap<DomainEvent, true> the instances already recorded; the map itself stays mutable */
    private WeakMap $recorded;

    public function __construct(
        private VaulterDatabase $database,
        private VaulterConfig $config,
        private Container $container,
        private MetricsRecorder $metrics,
    ) {
        $this->recorded = new WeakMap;
    }

    public function record(DomainEvent $event): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] EventRecorder::record() must run inside a VaulterDatabase transaction.');
        }
        if (isset($this->recorded[$event])) {
            throw new LogicException('[Vaulter] The event '.$event->eventId.' was already recorded.');
        }
        $this->recorded[$event] = true;

        // Resolved per call: the recorder is a singleton, the sinks are swappable.
        $sinks = [];
        if ($this->config->featureEnabled('activity') && $event->type()->isActivity()) {
            $sinks[] = fn (): bool => $this->container->make(ActivityRecorder::class)->record($event);
        }
        if ($this->config->featureEnabled('webhooks') && $event->isWebhookEligible()) {
            $sinks[] = fn (): bool => $this->container->make(OutboxRecorder::class)->stage($event, EventEnvelope::fromEvent($event));
        }

        // The first sink tells a new occurrence from a replay of one already stored
        // (a retried job recording the event it rebuilt). A replay touches nothing and
        // is not dispatched again; a new occurrence must land in every sink.
        $fresh = null;
        foreach ($sinks as $sink) {
            $inserted = $sink();
            if ($fresh === null) {
                $fresh = $inserted;
                if (!$fresh) {
                    return;
                }

                continue;
            }
            if (!$inserted) {
                throw new LogicException('[Vaulter] The event '.$event->eventId.' is stored only partially.');
            }
        }

        $this->database->afterCommit(function () use ($event): void {
            try {
                // Resolved at dispatch time, so a dispatcher swapped in later (a fake) sees it.
                $this->container->make(Dispatcher::class)->dispatch($event);
            } catch (Throwable $exception) {
                report($exception);
                $this->metrics->counter(
                    name: 'vaulter_event_listener_failures_total',
                    labels: ['type' => $event->type()->value],
                );
            }
        });
    }
}
