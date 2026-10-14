<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

/**
 * Whether the keyed write of the current request was answered from a receipt: the HTTP middleware reads it to
 * stamp `Idempotent-Replayed: true`. Scoped to the request (or job).
 *
 * @internal
 */
final class IdempotencyOutcome
{
    private bool $replayed = false;

    public function markReplayed(): void
    {
        $this->replayed = true;
    }

    public function replayed(): bool
    {
        return $this->replayed;
    }
}
