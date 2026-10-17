<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Vaulter\Attributes\Internal;

/** The state of one delivery row (event, consumer). */
#[Internal]
enum DeliveryState: string
{
    case Pending = 'pending';

    case Leased = 'leased';

    case Done = 'done';

    case Dead = 'dead';
}
