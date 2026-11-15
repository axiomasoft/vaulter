<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Vaulter\Attributes\Internal;

/** The lifecycle of a registered outbox consumer. */
#[Internal]
enum ConsumerState: string
{
    /** In the sealed registry: receives delivery rows and is reconciled. */
    case Active = 'active';

    /** Switched off or missing from the module graph: its rows wait, it holds retention up to the hold. */
    case Paused = 'paused';

    /** Paused longer than the hold: its open rows are dead and the range it missed is an explicit gap. */
    case Detached = 'detached';
}
