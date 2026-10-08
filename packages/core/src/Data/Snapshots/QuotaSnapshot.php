<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use Vaulter\Enums\Drive\QuotaState;

/** Read-only view of a drive's quota. Unlimited drives have a null limit and remaining. */
final readonly class QuotaSnapshot
{
    /** @internal */
    public function __construct(
        public ?int $limitBytes,
        public int $usedBytes,
        public int $trashedBytes,
        public ?int $remainingBytes,
        public QuotaState $state,
    ) {}
}
