<?php

declare(strict_types=1);

namespace Vaulter\Enums\Cas;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The blob lifecycle state machine:
 *
 *   Pending → Committed → Tombstoned → Deleting → Deleted
 *                ↑____________|
 *               (resurrection)
 *
 *  - Pending     — staged + a row exists, bytes not yet durable+verified. A
 *                  crashed staging leaves a Pending row swept on a timeout.
 *  - Committed   — bytes durable, sha256 server-verified; the live state.
 *  - Tombstoned  — GC marked it unreferenced; delete_after_at carries the grace
 *                  deadline. A concurrent commit RESURRECTS it back to Committed
 *                  in the same locked transaction (this is what makes online GC
 *                  safe).
 *  - Deleting    — the sweep claimed it; the physical delete is in flight.
 *  - Deleted     — bytes gone; the row is kept as an idempotent tombstone so a
 *                  re-run of the sweep is a no-op.
 */
enum BlobStatus: string
{
    use HasLabel;

    #[Label('Pending')]
    case Pending = 'pending';

    #[Label('Committed')]
    case Committed = 'committed';

    #[Label('Tombstoned')]
    case Tombstoned = 'tombstoned';

    #[Label('Deleting')]
    case Deleting = 'deleting';

    #[Label('Deleted')]
    case Deleted = 'deleted';

    /**
     * The states a blob may legally move to from this one. Used to fail loud on
     * an illegal transition rather than silently corrupting liveness.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Committed, self::Deleting, self::Deleted],
            // Committed → Tombstoned is the GC mark; it never goes straight to
            // Deleting (the grace window must elapse first).
            self::Committed => [self::Tombstoned],
            // Tombstoned → Committed is resurrection; → Deleting is the sweep.
            self::Tombstoned => [self::Committed, self::Deleting],
            self::Deleting => [self::Deleted],
            // Deleted is terminal; the row stays as an idempotent tombstone.
            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * A blob in a live state pins its bytes (no physical delete is permitted).
     * Committed obviously; Pending too, since an in-flight commit may still
     * attach an edge.
     */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Committed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
