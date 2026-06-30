<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;

/**
 * The command DTO for granting (or denying) a subject access to a node (§2.6).
 * Carries the subject, the level + optional extra CRUDS bits, the effect
 * (allow|deny), the scope (item|subtree) and an optional read-time expiry. Built
 * from a validated request and consumed only by {@see GrantNodeAccessAction}.
 */
final readonly class GrantNodeAccessData
{
    public function __construct(
        public Subject $subject,
        public GrantLevel $level,
        public Effect $effect = Effect::Allow,
        public GrantScope $scope = GrantScope::Subtree,
        public int $permissions = 0,
        public ?Carbon $expiresAt = null,
        public int|string|null $grantedBy = null,
    ) {}
}
