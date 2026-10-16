<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/** What an {@see EventSink} did with one event. */
#[Spi(SpiKind::Call)]
enum SinkResult: string
{
    /** The occurrence is new and was stored. */
    case Stored = 'stored';

    /** The occurrence was already stored: a replay. */
    case Replayed = 'replayed';

    /** The sink does not keep this event. */
    case Skipped = 'skipped';
}
