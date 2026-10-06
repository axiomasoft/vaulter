<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;

final readonly class GrantInput
{
    public function __construct(
        public Subject $subject,
        public GrantLevel $level,
        public Effect $effect = Effect::Allow,
        public GrantScope $scope = GrantScope::Subtree,
        public int $permissions = 0,
        public ?Carbon $expiresAt = null,
    ) {
        if ($permissions < 0 || ($permissions & ~Permission::all()) !== 0) {
            throw new InvalidArgumentException('Invalid grant permission bits.');
        }
        if (trim($subject->type) === '' || strlen($subject->type) > 255 || (string) $subject->id === '' || strlen((string) $subject->id) > 191) {
            throw new InvalidArgumentException('A grant requires a nonempty scalar subject identity.');
        }
    }
}
