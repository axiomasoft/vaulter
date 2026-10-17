<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\Access\GrantAuthoritativeDriver;
use Vaulter\Drive\Enums\Access\Effect;

/**
 * A grant-authoritative driver that broadens the subject-set: the actor PLUS a fixed
 * role/team subject (type+id), with NO role decision and NO override of its own — so
 * v_node_grants remains the SOLE authority. This proves the access-aware listing
 * candidate set follows {@see PermissionDriver::subjectsFor()}: a grant addressed to the
 * actor's ROLE (not the user directly) is reachable in the listing exactly as a direct
 * user grant would be.
 *
 * It carries the {@see GrantAuthoritativeDriver} marker for the same reason the
 * standalone {@see DatabasePermissionDriver} does — broadening the subject fan-out
 * does not introduce a non-grant authority, so the listing may still narrow safely.
 */
#[Internal]
final class RoleSubjectGrantDriver extends DatabasePermissionDriver implements GrantAuthoritativeDriver
{
    public function __construct(
        private readonly string $roleType,
        private readonly int|string $roleId,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        return null;
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return null;
    }

    /**
     * The actor itself PLUS a fixed role/team subject — the host's identity fan-out.
     *
     * @return list<Subject>
     */
    #[\Override]
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$actor instanceof Model) {
            return [];
        }

        return [
            Subject::ofModel($actor),
            new Subject(type: $this->roleType, id: $this->roleId),
        ];
    }
}
