<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Access;

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\EnumeratesRoleSubjects;
use Vaulter\Exceptions\VaulterException;

/**
 * A grant was addressed to a role/group/team subject under a host-role driver whose
 * `subjectsFor()` does not enumerate role subjects (F-P1.2-01), so the grant would
 * never take effect. Thrown OUTSIDE production to make the otherwise-silent no-op
 * loud; in production the write is only logged (the direction of failure is safe —
 * an inert grant grants nothing).
 */
final class UnenumerableGrantSubjectException extends VaulterException
{
    /**
     * @param class-string $driverClass
     */
    public static function for(Subject $subject, string $driverClass): self
    {
        return new self(
            sprintf(
                '[Vaulter] Grant addressed to subject [%s:%s] but the active host-role driver [%s] ',
                $subject->type,
                (string) $subject->id,
                $driverClass,
            )
            .'does not enumerate role/group/team subjects, so this grant would never take effect. '
            .'Why: the driver inherits subjectsFor() unchanged from a base lane that returns only '
            .'the actor, so a role/team grant never joins the AccessResolver subject-set. '
            .'Fix: use a driver that enumerates role subjects (spatie), or subclass your driver and '
            .'OVERRIDE subjectsFor() to append the role/team Subjects (optionally marking it '
            .EnumeratesRoleSubjects::class.' to advertise the capability explicitly). '
            .'Docs: '.self::docs('unenumerable-grant-subject'),
        );
    }
}
