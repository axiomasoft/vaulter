<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

/** Whether an outbox consumer must see every event (durable) or only the current ones (ephemeral). */
enum DeliveryClass: string
{
    /** Holds retention while paused and records an explicit gap when it is detached. */
    case Durable = 'durable';

    /** Control traffic (revocation, …): holds no retention and starts from the current seq without a gap. */
    case Ephemeral = 'ephemeral';
}
