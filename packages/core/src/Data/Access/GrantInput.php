<?php

declare(strict_types=1);

namespace Vaulter\Data\Access;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Attributes\Api;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Exceptions\Access\UnknownRoleException;

/**
 * A positive grant: the subject gets the newest version of the named role on the
 * node (and, for subtree, below it up to the next inheritance boundary).
 */
#[Api]
final readonly class GrantInput
{
    /**
     * @throws UnknownRoleException
     */
    public function __construct(
        public Subject $subject,
        public string $role,
        public GrantScope $scope = GrantScope::Subtree,
        public ?Carbon $expiresAt = null,
    ) {
        if (!(new RoleRegistry)->has($role)) {
            throw UnknownRoleException::for($role);
        }
        if (trim($subject->type) === '' || strlen($subject->type) > 255 || (string) $subject->id === '' || strlen((string) $subject->id) > 191) {
            throw new InvalidArgumentException('A grant requires a nonempty scalar subject identity.');
        }
    }
}
