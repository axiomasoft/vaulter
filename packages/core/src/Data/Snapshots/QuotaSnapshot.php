<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Drive\QuotaState;

/** Read-only view of a drive's quota. Unlimited drives have a null limit and remaining. */
#[Api]
final readonly class QuotaSnapshot
{
    #[Internal]
    public function __construct(
        public ?int $limitBytes,
        public int $usedBytes,
        public int $trashedBytes,
        public ?int $remainingBytes,
        public QuotaState $state,
    ) {}
}
