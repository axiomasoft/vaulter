<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Illuminate\Support\Carbon;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;

final readonly class GrantInput
{
    public function __construct(
        public Subject $subject,
        public GrantLevel $level,
        public Effect $effect = Effect::Allow,
        public GrantScope $scope = GrantScope::Subtree,
        public int $permissions = 0,
        public ?Carbon $expiresAt = null,
    ) {}
}
