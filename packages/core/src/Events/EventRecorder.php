<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Outbox\OutboxWriter;
use Vaulter\Kernel;
use WeakMap;

/**
 * The only way a domain event is recorded. Called as the last step of an operation's transaction, it hands
 * the occurrence to the {@see EventSink} contributions of the active modules in the order (priority, id) —
 * the drive's activity feed and change journal — then writes the outbox row and the delivery rows of the
 * subscribed outbox consumers on the Vaulter connection, whatever modules are active, and dispatches
 * the event to host listeners after the commit. The outbox comes last: its envelope carries the journal seq
 * a sink shared.
 *
 * A failed write is not caught here: it leaves the transaction, which rolls the change back together with
 * whatever was already recorded, so a committed change always has its rows. Host listeners are the
 * opposite: they run after the commit, and their failure is reported without undoing a change that is
 * already durable.
 */
#[Api]
final readonly class EventRecorder
{
    /** @var WeakMap<DomainEvent, true> the instances already recorded; the map itself stays mutable */
    private WeakMap $recorded;

    #[Internal]
    public function __construct(
        private VaulterDatabase $database,
        private Container $container,
        private MetricsRecorder $metrics,
        private Kernel $kernel,
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

        $writer = new SinkWriter($this->database);
        // Resolved per call: the host may swap its sinks within a job.
        $sinks = [];
        foreach ($this->kernel->extensions()->resolve(EventSink::class) as $sink) {
            $sinks[] = static fn (): SinkResult => $sink->record($event, $writer);
        }
        // The outbox is written always and last: its envelope carries the journal seq a sink shared.
        $sinks[] = fn (): SinkResult => $this->container->make(OutboxWriter::class)->append($event, $writer);

        // The first sink that keeps the event tells a new occurrence from a replay of one already stored
        // (a retried job recording the event it rebuilt). A replay touches nothing and is not dispatched
        // again; a new occurrence must land in every sink that keeps it.
        $fresh = null;
        foreach ($sinks as $sink) {
            $result = $sink();
            if ($result === SinkResult::Skipped) {
                continue;
            }
            if ($fresh === null) {
                $fresh = $result === SinkResult::Stored;
                if (!$fresh) {
                    return;
                }

                continue;
            }
            if ($result !== SinkResult::Stored) {
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
