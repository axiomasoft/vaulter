<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Fixtures\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Configuration\AccessOptions;

/**
 * A host driver following the DOCUMENTED extension path (review): it
 * subclasses the standalone {@see DatabasePermissionDriver} and OVERRIDES
 * subjectsFor() to append a role Subject — exactly as that driver's own docblock
 * promotes — but does NOT implement the {@see EnumeratesRoleSubjects}
 * marker. Its role grants genuinely take effect, so the fail-loud guard must NOT trip
 * for it. A no-arg constructor so the PermissionDriverManager can container-make it from
 * the permission_drivers map.
 */
#[Internal]
final class SubclassedRoleDriver extends DatabasePermissionDriver
{
    /**
     * The actor itself PLUS its (fixed) role Subject — the host identity fan-out that
     * makes a role grant reachable.
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
            new Subject(type: app(AccessOptions::class)->spatieRoleSubjectType, id: '7'),
        ];
    }
}
