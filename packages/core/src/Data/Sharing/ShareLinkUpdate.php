<?php

declare(strict_types=1);

namespace Vaulter\Data\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Enums\Sharing\ShareAudience;

/**
 * Changes to an existing share link. A null field stays as it is; the
 * token never changes (use rotate for that). The role and the scope are fixed at
 * creation: a different grant is a different link.
 */
final readonly class ShareLinkUpdate
{
    public function __construct(
        public ?Carbon $expiresAt = null,
        public ?string $password = null,
        public ?int $maxUses = null,
        public ?ShareAudience $audience = null,
        public bool $removePassword = false,
        public bool $removeMaxUses = false,
    ) {}
}
