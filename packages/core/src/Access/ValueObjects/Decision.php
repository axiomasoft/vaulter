<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use LogicException;
use Vaulter\Access\Roles\Role;
use Vaulter\Attributes\Api;

/**
 * A PermissionDriver's answer for one subject: the host role system gives this role
 * here. Decisions only add abilities — they are unioned with the node grants; an
 * administrative block is PermissionDriver::override(), not a Decision.
 */
#[Api]
final readonly class Decision
{
    public function __construct(
        public Role $role,
    ) {}

    /**
     * The Decision for every role a host grants at once, or null for none. A role
     * map lists nesting roles, so their union is the role that covers the others.
     *
     * @param list<Role> $roles
     */
    public static function union(array $roles): ?self
    {
        if ($roles === []) {
            return null;
        }

        return new self(role: Role::covering($roles) ?? throw new LogicException('[Vaulter] A permission driver granted roles whose union is not a role.'));
    }
}
