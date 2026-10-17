<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * A slot (`SlotKind::EventSink`, defined by core) that stores each domain event inside the transaction of the
 * operation that recorded it (architecture#events): the drive contributes its activity feed and change
 * journal. Sinks run in the order (priority, id); the kernel writes the outbox after the last of them.
 *
 * A sink tells a new occurrence from a replay of one already stored: the first sink that stores the event
 * decides, a replay touches nothing and is not dispatched again, and a sink that sees a replay of an
 * occurrence another sink stored as new is an error (the event is stored only partially). A sink that does
 * not keep the event (a type it ignores, its feature switched off) answers {@see SinkResult::Skipped}.
 *
 * A failure is not caught: it leaves the transaction and rolls the operation back.
 */
#[Spi(SpiKind::Implement)]
interface EventSink
{
    public function record(DomainEvent $event, SinkWriter $writer): SinkResult;
}
