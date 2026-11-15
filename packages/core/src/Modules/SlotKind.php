<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Api;

/**
 * What a contribution to a slot may do. The kind is fixed by the module that defines the slot;
 * an observer is an ordinary Laravel listener, not a slot.
 */
#[Api]
enum SlotKind: string
{
    /** May veto: an exception denies the operation, fail-closed. */
    case Guard = 'guard';

    /** May only narrow the constraints it is given. */
    case Restriction = 'restriction';

    /** Adds named fragments to a result, in one batch. */
    case Contributor = 'contributor';

    /** Serves one key (a kind, a protocol, a driver name); the consumer picks it by the key. */
    case Handler = 'handler';

    /** Receives the events of an operation inside its transaction. */
    case EventSink = 'event-sink';

    /** Consumes the outbox after commit under its own cursor. */
    case OutboxConsumer = 'outbox-consumer';

    /** Wraps an operation and calls the next stage exactly once. */
    case OperationMiddleware = 'operation-middleware';
}
