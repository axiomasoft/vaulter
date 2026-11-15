<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Vaulter\Attributes\Api;

/**
 * What a repeat of an operation does:
 *
 * - `receipt` — the claim and receipt of an `Idempotency-Key` (its routes carry the `ReadIdempotencyKey` middleware);
 * - `natural` — a repeat without a key has no second effect;
 * - `conditional` — a repeat answers by its precondition (the same `If-Match` after the commit is a 412);
 * - `protocol` — a machine protocol with a deduplication of its own (update ids, receipts, generations).
 */
#[Api]
enum IdempotencyPolicy: string
{
    case Receipt = 'receipt';
    case Natural = 'natural';
    case Conditional = 'conditional';
    case Protocol = 'protocol';
}
