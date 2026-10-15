<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

/** The state of one delivery row (event, consumer). */
enum DeliveryState: string
{
    case Pending = 'pending';

    case Leased = 'leased';

    case Done = 'done';

    case Dead = 'dead';
}
