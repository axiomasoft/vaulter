<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\ValueObjects;

use Illuminate\Support\Carbon;
use Vaulter\Attributes\Api;

/**
 * One role that applies to the explained node: a grant (direct or inherited from
 * originNodeId) or, with a null grantId, the PermissionDriver's decision. Carries
 * ids only — never names of nodes the subject may not see.
 */
#[Api]
final readonly class AccessSource
{
    public function __construct(
        public int|string|null $grantId,
        public Subject $subject,
        public string $role,
        public int $roleVersion,
        public int|string|null $originNodeId,
        public bool $inherited,
        public ?Carbon $expiresAt,
    ) {}
}
