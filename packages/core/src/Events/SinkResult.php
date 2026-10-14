<?php

declare(strict_types=1);

namespace Vaulter\Events;

/** What an {@see EventSink} did with one event. */
enum SinkResult: string
{
    /** The occurrence is new and was stored. */
    case Stored = 'stored';

    /** The occurrence was already stored: a replay. */
    case Replayed = 'replayed';

    /** The sink does not keep this event. */
    case Skipped = 'skipped';
}
