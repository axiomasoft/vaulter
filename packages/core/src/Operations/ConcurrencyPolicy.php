<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Vaulter\Attributes\Api;

/**
 * How an operation keeps a concurrent writer from losing its change:
 *
 * - `if_match` — a change of an existing aggregate against its own version; `If-Match` is required (428, then 412);
 * - `append` — the creation of a new row, which has no version yet;
 * - `natural` — an absolute set or a membership: a repeat ends in the same state (star, a retention flag);
 * - `state_machine` — a transition checked against the current state under the aggregate lock (uploads);
 * - `generation` — fencing by the collaboration generation (409 `collaboration_*`);
 * - `row_identity` — the removal of an immutable row by its id (a second one answers 404).
 */
#[Api]
enum ConcurrencyPolicy: string
{
    case IfMatch = 'if_match';
    case Append = 'append';
    case Natural = 'natural';
    case StateMachine = 'state_machine';
    case Generation = 'generation';
    case RowIdentity = 'row_identity';
}
