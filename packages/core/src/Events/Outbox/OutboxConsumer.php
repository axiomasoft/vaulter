<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Events\EventTypeContract;

/**
 * A slot (`SlotKind::OutboxConsumer`, defined by core) that receives the outbox events after commit, at least
 * once, under its own delivery rows. The contribution id is the consumer id: `webhooks.fanout`, … The
 * outbox writer gives every consumer of the sealed registry that {@see subscribes()} to a type a delivery row in
 * the transaction of the operation; `vaulter:relay` reconciles the rows a process with an older module graph
 * missed, then leases due rows and calls {@see consume()} outside any transaction.
 *
 * A consumer changes the core tables only through {@see DeliveryControl}; it deduplicates by
 * {@see OutboxEnvelope::$eventId} and expects no order between events.
 */
#[Spi(SpiKind::Implement)]
interface OutboxConsumer
{
    /** The consumer id; equal to the id of its slot contribution. */
    public function id(): string;

    /** A durable consumer holds retention and records a gap when detached; an ephemeral one does neither. */
    public function deliveryClass(): DeliveryClass;

    public function subscribes(EventTypeContract $type): bool;

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult;
}
