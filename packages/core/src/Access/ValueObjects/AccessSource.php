<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Illuminate\Support\Carbon;

/**
 * One role that applies to the explained node: a grant (direct or inherited from
 * originNodeId) or, with a null grantId, the PermissionDriver's decision. Carries
 * ids only — never names of nodes the subject may not see.
 */
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
